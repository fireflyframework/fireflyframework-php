<?php

declare(strict_types=1);

use Firefly\Context\Lifecycle\InitDestroyInvoker;
use Firefly\Context\Lifecycle\PostConstruct;
use Firefly\Context\Processor\BeanPostProcessor;
use Firefly\Context\Processor\BeanPostProcessorChain;
use Illuminate\Container\Container;

/**
 * BeanPostProcessorChain is exercised through REAL BeanPostProcessor implementations (never
 * mocks of our own interfaces) and a REAL InitDestroyInvoker over a REAL Illuminate container,
 * following the ContractProbe idiom from KernelContractTest.php.
 */

/** Shared recorder: every fixture below appends a label so the exact call sequence is assertable. */
final class ChainLog
{
    /** @var list<string> */
    public array $entries = [];

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }
}

/** A plain bean with a #[PostConstruct] method that records into the shared ChainLog. */
final class ChainBean
{
    public function __construct(private readonly ChainLog $log) {}

    #[PostConstruct]
    public function init(): void
    {
        $this->log->record('PostConstruct');
    }
}

final class ChainNoLifecycleBean {}

final class ChainReplacementBean {}

/** A composition-style wrapper a proxying BPP can return from afterInitialization(). */
final class ChainProxyWrapper
{
    public function __construct(public readonly object $inner) {}
}

/** Records before(label)/after(label) into the shared log; passes the bean through unchanged. */
final class RecordingBpp implements BeanPostProcessor
{
    public function __construct(private readonly string $label, private readonly ChainLog $log) {}

    public function beforeInitialization(object $bean, string $declaredClass): object
    {
        $this->log->record("before({$this->label})");

        return $bean;
    }

    public function afterInitialization(object $bean, string $declaredClass): object
    {
        $this->log->record("after({$this->label})");

        return $bean;
    }
}

/** beforeInitialization() REPLACES the bean outright with a fixed replacement object. */
final class ReplacingBpp implements BeanPostProcessor
{
    public function __construct(private readonly object $replacement) {}

    public function beforeInitialization(object $bean, string $declaredClass): object
    {
        return $this->replacement;
    }

    public function afterInitialization(object $bean, string $declaredClass): object
    {
        return $bean;
    }
}

/** Records exactly what object it received at each pass, without transforming it. */
final class RecordingReceiptBpp implements BeanPostProcessor
{
    public ?object $receivedBefore = null;

    public ?object $receivedAfter = null;

    public function beforeInitialization(object $bean, string $declaredClass): object
    {
        $this->receivedBefore = $bean;

        return $bean;
    }

    public function afterInitialization(object $bean, string $declaredClass): object
    {
        $this->receivedAfter = $bean;

        return $bean;
    }
}

/** Wraps the bean in afterInitialization() ONLY — the M9/M12 proxy seam (invariant 4 corollary). */
final class WrappingBpp implements BeanPostProcessor
{
    public ?object $wrapped = null;

    public function beforeInitialization(object $bean, string $declaredClass): object
    {
        return $bean;
    }

    public function afterInitialization(object $bean, string $declaredClass): object
    {
        $this->wrapped = $bean;

        return new ChainProxyWrapper($bean);
    }
}

/**
 * @param  list<BeanPostProcessor>  $ordered
 */
function makeChain(array $ordered): BeanPostProcessorChain
{
    return new BeanPostProcessorChain($ordered, new InitDestroyInvoker(new Container));
}

// --- invariant 5: per-bean sequencing, not a global two-sweep ---

it('runs before(A), before(B), #[PostConstruct], after(A), after(B) — PER BEAN, not all-beans x beforeInit (invariant 5)', function () {
    $log = new ChainLog;
    $chain = makeChain([new RecordingBpp('A', $log), new RecordingBpp('B', $log)]);

    $chain->process(new ChainBean($log), ChainBean::class);

    expect($log->entries)->toBe(['before(A)', 'before(B)', 'PostConstruct', 'after(A)', 'after(B)']);
});

// --- ordering + replacement propagation ---

it('runs BPPs in the frozen constructor order for BOTH passes', function () {
    $log = new ChainLog;
    // Constructed in B-then-A order — the chain must run exactly that order, not re-sort it.
    $chain = makeChain([new RecordingBpp('B', $log), new RecordingBpp('A', $log)]);

    $chain->process(new ChainNoLifecycleBean, ChainNoLifecycleBean::class);

    expect($log->entries)->toBe(['before(B)', 'before(A)', 'after(B)', 'after(A)']);
});

it('propagates a beforeInitialization() replacement to the NEXT BPP in the chain', function () {
    $replacement = new ChainReplacementBean;
    $second = new RecordingReceiptBpp;
    $chain = makeChain([new ReplacingBpp($replacement), $second]);

    $chain->process(new ChainNoLifecycleBean, ChainNoLifecycleBean::class);

    expect($second->receivedBefore)->toBe($replacement);
});

it("the chain's final return is what the LAST BPP's afterInitialization() produced", function () {
    $replacement = new ChainReplacementBean;
    $chain = makeChain([new ReplacingBpp($replacement)]);

    $result = $chain->process(new ChainNoLifecycleBean, ChainNoLifecycleBean::class);

    expect($result)->toBe($replacement);
});

// --- invariant 4 (+ 5): a proxying BPP wraps in pass 2 ONLY; #[PostConstruct] already fired ---

it('a proxying BPP wraps the bean in afterInitialization(), yet #[PostConstruct] already fired against the pre-wrap instance using the declared class (invariant 4)', function () {
    $log = new ChainLog;
    $wrappingBpp = new WrappingBpp;
    $chain = makeChain([$wrappingBpp]);
    $bean = new ChainBean($log);

    $result = $chain->process($bean, ChainBean::class);

    expect($log->entries)->toBe(['PostConstruct'])
        ->and($result)->toBeInstanceOf(ChainProxyWrapper::class)
        ->and($wrappingBpp->wrapped)->toBe($bean);
});

// --- the \WeakMap idempotency guard ---

it('the \WeakMap guard makes processing the SAME bean instance twice run the chain only once', function () {
    $log = new ChainLog;
    $chain = makeChain([new RecordingBpp('A', $log)]);
    $bean = new ChainBean($log);

    $first = $chain->process($bean, ChainBean::class);
    $second = $chain->process($bean, ChainBean::class);

    expect($log->entries)->toBe(['before(A)', 'PostConstruct', 'after(A)'])
        ->and($second)->toBe($first);
});

it('the \WeakMap guard keys on the ORIGINAL bean instance and still returns the cached (possibly wrapped) result', function () {
    $log = new ChainLog;
    $chain = makeChain([new WrappingBpp]);
    $bean = new ChainBean($log);

    $first = $chain->process($bean, ChainBean::class);
    $second = $chain->process($bean, ChainBean::class);

    expect($log->entries)->toBe(['PostConstruct'])
        ->and($second)->toBe($first)
        ->and($second)->toBeInstanceOf(ChainProxyWrapper::class);
});

it('an empty BPP list still runs #[PostConstruct] and returns the bean unchanged', function () {
    $log = new ChainLog;
    $chain = makeChain([]);
    $bean = new ChainBean($log);

    $result = $chain->process($bean, ChainBean::class);

    expect($log->entries)->toBe(['PostConstruct'])
        ->and($result)->toBe($bean);
});
