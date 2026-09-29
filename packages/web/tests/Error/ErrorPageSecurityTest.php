<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Web\Error\ErrorPageSettings;
use Illuminate\Config\Repository;

/**
 * The scheme guard on every operator-supplied href, and the reason it lives in the CONSTRUCTOR.
 *
 * The error page is a place a framework hands ITSELF whatever an operator put in an environment variable.
 * htmlspecialchars is the only thing between that string and a click, and it escapes quotes and brackets —
 * not schemes. `javascript:alert(document.cookie)` survives it intact, and an error page is a surface a
 * person reaches while already confused, on the origin that holds their session. Guarding at RENDER time
 * would be one forgotten call site away from the same hole, and guarding in fromConfig() alone would cover
 * only the construction path the framework itself uses — `firefly/security` builds these settings by hand
 * for its login page and a dozen tests construct them directly. Guarding in the constructor is what makes
 * the sentence true: the settings object cannot HOLD an unsafe URL, whoever built it and whichever surface
 * prints it.
 */
$settings = static fn (array $errorPage): ErrorPageSettings => ErrorPageSettings::fromConfig(
    new Config(new Repository(['firefly' => ['web' => ['error-page' => $errorPage]]])),
);

it('refuses a javascript: URL from configuration, in every slot that reaches an href', function () use ($settings) {
    $hostile = $settings([
        'home' => 'javascript:alert(document.cookie)',
        'sign-in' => 'JavaScript:alert(1)',
        'support' => "java\tscript:alert(1)",
    ]);

    expect($hostile->home)->toBe('')
        ->and($hostile->signIn)->toBe('')
        ->and($hostile->support)->toBe('');
});

it('refuses every other scheme that is not an ordinary web link', function () use ($settings) {
    foreach (['data:text/html;base64,PHN2Zy9vbmxvYWQ9YWxlcnQoMSk+', 'vbscript:msgbox(1)', 'file:///etc/passwd', 'mailto:ops@example.test', '//evil.test/phish', '\\\\evil.test\\share'] as $value) {
        expect($settings(['support' => $value])->support)->toBe('');
    }
});

/**
 * The two ways a value that begins with ONE slash still leaves the origin.
 *
 * A guard that refuses `//host/…` and stops there is refusing a spelling rather than a behaviour, and both
 * of the values it lets through land on a page a confused person is already standing on — which is what
 * makes the redirect credible. For a special scheme the URL standard's relative-slash state treats `\`
 * exactly like `/`, so a browser resolves `/\evil.test/phish` against this origin as
 * `https://evil.test/phish`; and before any parsing happens every ASCII tab, LF and CR is DELETED from the
 * input, so `/<TAB>/evil.test` is `//evil.test` by the time the resolver sees it. Each of these begins with
 * one slash and not two, and each is the phishing redirect the protocol-relative case is refused for.
 */
it('refuses every value a browser resolves off this origin, whichever separator the authority hides behind', function () use ($settings) {
    foreach (['/\\evil.test/phish', '/\\/evil.test', '/\\\\evil.test', "/\t/evil.test/phish", "/\n/evil.test/phish", "/\r/evil.test/phish", "//\tevil.test", 'https:/\\evil.test'] as $value) {
        expect($settings(['support' => $value])->support)->toBe('');
    }
});

it('keeps an absolute path and an http(s) URL, which is the whole legitimate vocabulary', function () use ($settings) {
    $good = $settings([
        'home' => '/',
        'sign-in' => '/login?next=%2Forders',
        'support' => 'https://support.example.test/tickets/new',
    ]);

    expect($good->home)->toBe('/')
        ->and($good->signIn)->toBe('/login?next=%2Forders')
        ->and($good->support)->toBe('https://support.example.test/tickets/new')
        ->and($settings(['support' => 'HTTP://legacy.example.test/help'])->support)->toBe('HTTP://legacy.example.test/help');
});

/**
 * Padding at the EDGES is the deployment; padding INSIDE is the attack.
 *
 * These values arrive from the mechanism the guard's own docblock names — a Helm value, a CI-rendered .env,
 * a tenant-provisioning job — and a Helm block scalar and a here-doc-rendered variable both end in a
 * newline. Dotenv trims a `.env` LINE; Laravel's `Env` does not touch a real environment variable, so
 * "https://support.example.test/tickets/new\n" is exactly what the settings class is handed, and refusing
 * it deleted an operator's link with no exception and no log over a character no reader ever sees. The URL
 * standard strips leading and trailing C0 controls and space BEFORE it parses, so trimming them decides the
 * value exactly as the browser will. An INTERIOR tab, LF or CR is the opposite case: the parser deletes
 * those from the middle, which is the whole reason `java<TAB>script:` is a javascript: URL, so they are
 * still refused. Both halves are asserted together because the guard's first shape refused both, and the
 * two can never be conflated again — trimming first gives up nothing, since every hostile value trims into
 * another one the guard already refuses.
 */
it('trims the whitespace a deployment adds at the edges, and still refuses it in the middle', function () use ($settings) {
    expect($settings(['support' => "https://support.example.test/tickets/new\n"])->support)->toBe('https://support.example.test/tickets/new')
        ->and($settings(['home' => " /dashboard\r\n"])->home)->toBe('/dashboard')
        ->and($settings(['support' => 'https://support.example.test '])->support)->toBe('https://support.example.test');

    foreach (["/log\tin", "https://support.example.test/a\nb", "java\tscript:alert(1)", ' //evil.test', "\x00/\\evil.test", "\tjavascript:alert(1)", "   \n  "] as $value) {
        expect($settings(['support' => $value])->support)->toBe('');
    }
});

/**
 * The fifth configuration-supplied URI, and the first one that is not an `href`.
 *
 * `firefly.web.problem.type-uri` is the base ProblemType builds RFC 9457's `type` out of, and the RFC's own
 * word for that member is "dereferenceable" — it exists so a person can open it. Nothing on the error PAGE
 * prints it, which is exactly why it went unguarded while the four beside it did not: the audit that
 * produced ErrorPageSettings::url() followed hrefs, and this value leaves by a different door. Every API
 * console, IDE HTTP client and documentation viewer that renders a problem document turns `type` into a
 * link, so `javascript:` here is the same stored XSS in front of a wider audience.
 *
 * AND THE SENTINELS ARE COMPARED WITH `===`, which makes the trim load-bearing rather than cosmetic.
 * ProblemType::of() asks whether the base IS 'about:blank' and whether it IS ''; Config::string() does not
 * trim and Laravel's Env does not touch a real environment variable, so the Helm block scalar and the
 * here-doc-rendered `.env` this class's other guard was written for made BOTH answers false and quietly
 * moved the deployment from the default into base-URI mode — publishing `" about:blank/resource-not-found"`
 * as a URI a console invites a reader to click.
 */
$problemType = static fn (string $value): string => ErrorPageSettings::fromConfig(
    new Config(new Repository(['firefly' => ['web' => ['problem' => ['type-uri' => $value]]]])),
)->typeUri;

it('keeps the two sentinels and an absolute http(s) base, which is the whole legitimate vocabulary', function () use ($problemType) {
    expect($problemType('about:blank'))->toBe('about:blank')
        ->and($problemType(''))->toBe('')
        ->and($problemType('https://api.example.test/problems'))->toBe('https://api.example.test/problems')
        ->and($problemType('HTTP://legacy.example.test/p'))->toBe('HTTP://legacy.example.test/p')
        // The default is the sentinel, for a settings object read from an empty configuration and for one
        // built by hand — the guard runs in the constructor, like the four above it.
        ->and(ErrorPageSettings::fromConfig(new Config(new Repository([])))->typeUri)->toBe('about:blank')
        ->and((new ErrorPageSettings)->typeUri)->toBe('about:blank');
});

it('trims the padding a deployment adds at the edges, so the RFC sentinel still reads as the sentinel', function () use ($problemType) {
    // Each of these used to flip the key out of default mode: ProblemType::of() compares with ===, so a
    // padded sentinel matched neither branch and became a base URI with a space or a newline inside it.
    expect($problemType(' about:blank'))->toBe('about:blank')
        ->and($problemType("about:blank\n"))->toBe('about:blank')
        ->and($problemType("\tabout:blank\r\n"))->toBe('about:blank')
        ->and($problemType("https://api.example.test/problems\n"))->toBe('https://api.example.test/problems')
        ->and($problemType(' https://api.example.test/problems '))->toBe('https://api.example.test/problems')
        // Whitespace and nothing else is the value an operator left blank, and '' is what it means: the
        // member is omitted, which is the pre-9457 document byte for byte.
        ->and($problemType("  \n "))->toBe('');
});

it('refuses a hostile or relative base and falls back to the documented default rather than to silence', function () use ($problemType) {
    foreach ([
        'javascript:alert(document.cookie)',
        'JavaScript:alert(1)',
        "java\tscript:alert(1)",
        'data:text/html;base64,PHN2Zy9vbmxvYWQ9YWxlcnQoMSk+',
        'vbscript:msgbox(1)',
        'file:///etc/passwd',
        '//evil.test/problems',
        '/\\evil.test/problems',
        '/problems',
        'problems',
        'about:blank/',
        "https://api.example.test/pro\nblems",
        "https://api.example.test/pro\tblems",
    ] as $value) {
        // 'about:blank' and not '': '' is a position an operator takes deliberately, and a typo must not be
        // able to take it for them. The answer to a hostile base is to remove the hostility, not the member.
        expect($problemType($value))->toBe('about:blank', sprintf('%s reached the published `type`', var_export($value, true)));
    }

    expect((new ErrorPageSettings(typeUri: 'javascript:alert(document.cookie)'))->typeUri)->toBe('about:blank')
        ->and((new ErrorPageSettings(typeUri: ' about:blank'))->typeUri)->toBe('about:blank');
});

it('defaults home to the site root, offers no sign-in or support link, and renders the action row', function () use ($settings) {
    $defaults = $settings([]);

    expect($defaults->home)->toBe('/')
        ->and($defaults->signIn)->toBe('')
        ->and($defaults->support)->toBe('')
        ->and($defaults->actions)->toBeTrue();
});

it('lets an operator switch the action row off entirely', function () use ($settings) {
    expect($settings(['actions' => false])->actions)->toBeFalse();
});

/**
 * The guard holds for a settings object nobody read out of configuration.
 *
 * `firefly/security` builds an ErrorPageSettings by hand for the login page, and this package's own render
 * tests construct one directly. A guard that ran only in fromConfig() would have covered exactly one call
 * site while the class advertised an invariant over all of them; the constructor is the seam every path
 * goes through, so this is the assertion that the advertisement is honest.
 */
it('guards a settings object built by hand, not only one read from configuration', function () {
    $hostile = new ErrorPageSettings(
        home: 'javascript:alert(document.cookie)',
        signIn: '/\\evil.test/login',
        support: "java\tscript:alert(1)",
    );

    expect($hostile->home)->toBe('')
        ->and($hostile->signIn)->toBe('')
        ->and($hostile->support)->toBe('');

    $good = new ErrorPageSettings(home: '/dashboard', support: 'https://support.example.test');

    expect($good->home)->toBe('/dashboard')
        ->and($good->support)->toBe('https://support.example.test')
        ->and((new ErrorPageSettings)->home)->toBe('/');
});
