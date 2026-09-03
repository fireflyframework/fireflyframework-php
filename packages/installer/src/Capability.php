<?php

declare(strict_types=1);

namespace Firefly\Installer;

/**
 * One selectable capability in the `firefly new` archetype picker — the Spring Initializr "dependency".
 *
 * A capability is a NAME A HUMAN PICKS ("security", "eda") mapped onto the firefly/* Composer package that
 * implements it. The indirection is deliberate: the ids are the vocabulary of the prompt and of `--with=`,
 * and they stay stable even if a package is ever split or renamed, whereas a raw package name in a shell
 * flag would be both longer to type and impossible to alias.
 */
final readonly class Capability
{
    /**
     * @param  string  $id  the id the user types in `--with=` and picks in the prompt
     * @param  string  $package  the Composer package that implements it
     * @param  string  $summary  one line, shown in the interactive picker
     * @param  bool  $dev  true when the package belongs in require-dev, not require (the test kit)
     * @param  list<string>  $requires  capability ids this one implies — an adapter always implies its port
     * @param  bool  $adapter  true when the package binds the app to one specific piece of infrastructure
     */
    public function __construct(
        public string $id,
        public string $package,
        public string $summary,
        public bool $dev = false,
        public array $requires = [],
        public bool $adapter = false,
    ) {}

    /**
     * The label the interactive picker shows. Symfony's multi-select ChoiceQuestion matches on the ARRAY
     * KEY, not on this label, so the summary can be as long as it needs to be without becoming something
     * the user has to retype.
     */
    public function label(): string
    {
        return $this->id.' — '.$this->summary;
    }
}
