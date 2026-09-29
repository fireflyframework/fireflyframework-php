<?php

declare(strict_types=1);

namespace Firefly\Admin;

/** Iterative Tarjan: stack depth is independent of the PHP call stack.
 */
final readonly class GraphComponents
{
    /**
     * @param  list<list<string>>  $groups
     * @param  array<string,int>  $membership
     */
    private function __construct(public array $groups, public array $membership) {}

    /**
     * @param  list<string>  $ids
     * @param  array<string,list<string>>  $out
     */
    public static function of(array $ids, array $out): self
    {
        $index = $low = $active = $membership = [];
        $stack = $groups = [];
        $next = 0;
        foreach ($ids as $root) {
            if (isset($index[$root])) {
                continue;
            }
            $frames = [[$root, 0, null]];
            while ($frames !== []) {
                $top = count($frames) - 1;
                [$node, $offset, $parent] = $frames[$top];
                if (! isset($index[$node])) {
                    $index[$node] = $low[$node] = $next++;
                    $stack[] = $node;
                    $active[$node] = true;
                }
                $neighbors = $out[$node] ?? [];
                if ($offset < count($neighbors)) {
                    $target = $neighbors[$offset];
                    $frames[$top][1]++;
                    if (! isset($index[$target])) {
                        $frames[] = [$target, 0, $node];
                    } elseif (isset($active[$target])) {
                        $low[$node] = min($low[$node], $index[$target]);
                    }

                    continue;
                }
                array_pop($frames);
                if ($parent !== null) {
                    $low[$parent] = min($low[$parent], $low[$node]);
                }
                if ($low[$node] === $index[$node]) {
                    $group = [];
                    do {
                        $member = array_pop($stack);
                        if ($member === null) {
                            break;
                        }
                        unset($active[$member]);
                        $membership[$member] = count($groups);
                        $group[] = $member;
                    } while ($member !== $node);
                    sort($group);
                    $groups[] = $group;
                }
            }
        }

        return new self($groups, $membership);
    }
}
