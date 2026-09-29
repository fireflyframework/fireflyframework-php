<?php

declare(strict_types=1);

use Firefly\Tests\Browser\Support\BrowserTestCase;

pest()->extend(BrowserTestCase::class);

it('renders every generated operation, tag and response from the compiled skeleton', function (): void {
    $page = visit('/openapi')->assertPresent('.opblock-summary-control');
    $page->script("document.querySelectorAll('.opblock-summary-control').forEach(button => button.click())");
    $page->assertScript(<<<'JS'
        (() => {
            const spec = window.ui.specSelectors.specJson().toJS();
            const expected = Object.entries(spec.paths).flatMap(([path, verbs]) =>
                Object.entries(verbs).flatMap(([verb, operation]) => operation.tags.map(tag => ({path, verb, operation, tag}))));
            const blocks = [...document.querySelectorAll('.opblock')];
            return blocks.length === expected.length && expected.every(({path, verb, operation, tag}) => {
                const block = blocks.find(block =>
                    block.querySelector('.opblock-summary-path')?.dataset.path === path &&
                    block.querySelector('.opblock-summary-method')?.textContent.toLowerCase() === verb &&
                    block.closest('.opblock-tag-section').querySelector('.opblock-tag').dataset.tag === tag);
                const statuses = [...(block?.querySelectorAll('tbody td.response-col_status') ?? [])].map(cell => cell.textContent.trim());
                return JSON.stringify(statuses.sort()) === JSON.stringify(Object.keys(operation.responses).sort());
            }) && (spec.tags ?? []).every(tag => [...document.querySelectorAll('.opblock-tag')].some(element =>
                element.dataset.tag === tag.name && element.textContent.replace(/\s+/g, ' ').includes(tag.description.replace(/`/g, '').replace(/\s+/g, ' '))));
        })()
        JS, true)
        ->assertScript('window.ui.errSelectors.allErrors().size', 0)
        ->assertScript("performance.getEntriesByType('resource').every(resource => new URL(resource.name).origin === location.origin)", true)
        ->assertNoJavaScriptErrors();
});

it('expands every component with its properties and resolves the complete generated contract', function (): void {
    $page = visit('/openapi')->assertPresent('section.models article');
    $page->script(<<<'JS'
        document.querySelectorAll('section.models article[data-json-schema-level="0"] > .json-schema-2020-12-head > .json-schema-2020-12-expand-deep-button').forEach(button => button.click())
        JS);
    $page->assertScript(<<<'JS'
        (() => {
            const spec = window.ui.specSelectors.specJson().toJS();
            const models = [...document.querySelectorAll('section.models article[data-json-schema-level="0"]')];
            const schemas = Object.entries(spec.components.schemas);
            const unresolved = [];
            function inspect(value) {
                if (!value || typeof value !== 'object') return;
                if (value.$ref) {
                    const target = value.$ref.slice(2).split('/').reduce((node, part) => node?.[part.replace(/~1/g, '/').replace(/~0/g, '~')], spec);
                    if (!value.$ref.startsWith('#/') || !target) unresolved.push(value.$ref);
                }
                Object.values(value).forEach(inspect);
            }
            inspect(spec);
            const operations = Object.entries(spec.paths).flatMap(([path, verbs]) => Object.values(verbs).map(operation => ({path, operation})));
            const ids = operations.map(({operation}) => operation.operationId);
            return unresolved.length === 0 && new Set(ids).size === ids.length &&
                operations.every(({path, operation}) => [...path.matchAll(/\{([^}]+)\}/g)].every(([, name]) =>
                    operation.parameters?.some(parameter => parameter.in === 'path' && parameter.name === name && parameter.required))) &&
                models.length === schemas.length && schemas.every(([name, schema]) => {
                    const model = models.find(model => model.querySelector('.json-schema-2020-12__title').textContent === (schema.title ?? name));
                    return model && Object.keys(schema.properties ?? {}).every(property => model.innerText.includes(property));
                });
        })()
        JS, true)
        ->assertSee('postcode')
        ->assertSee('unitPrice')
        ->assertSee('rejectedValue')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'openapi-expanded-schemas');
});

it('opens nested request and error schemas with the keyboard on a phone', function (): void {
    visit('/openapi')->on()->mobile()
        ->assertPresent('.opblock-post .opblock-summary-control')
        ->keys('.opblock-post .opblock-summary-control', 'Enter')
        ->assertSee('Request body')
        ->keys('.opblock-post .opblock-section-request-body .tab li:last-child button', 'Enter')
        ->assertSee('OrderRequest')
        ->keys('.opblock-post .opblock-section-request-body article[data-json-schema-level="0"] > .json-schema-2020-12-head > .json-schema-2020-12-expand-deep-button', 'Enter')
        ->assertSee('postcode')
        ->assertSee('unitPrice')
        ->keys('.opblock-post tr[data-code="422"] .tab li:last-child button', 'Enter')
        ->keys('.opblock-post tr[data-code="422"] article[data-json-schema-level="0"] > .json-schema-2020-12-head > .json-schema-2020-12-expand-deep-button', 'Enter')
        ->assertSee('constraint')
        ->assertSee('rejectedValue')
        ->assertScript("[...document.querySelectorAll('.opblock-post .responses-wrapper select')].some(select => select.value === 'application/problem+json')", true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'openapi-phone-contract');
});

it('keeps the reference text readable in either system theme', function (bool $dark): void {
    $page = visit('/openapi');
    if ($dark) {
        $page->inDarkMode();
    }
    $reference = $page->assertPresent('.opblock-post .opblock-summary-control')
        ->click('.opblock-post .opblock-summary-control')
        ->assertSee('Request body')
        ->assertScript("(async () => (await axe.run(document.getElementById('swagger-ui'), {runOnly:['color-contrast']})).violations.flatMap(violation => violation.nodes.map(node => node.target)))()", [])
        ->assertNoJavaScriptErrors();
    $reference->script('document.querySelectorAll(\'section.models article[data-json-schema-level="0"] > .json-schema-2020-12-head > .json-schema-2020-12-expand-deep-button\').forEach(button => button.click())');
    $reference->assertSee('postcode')
        ->assertScript("(async () => (await axe.run(document.querySelector('section.models'), {runOnly:['color-contrast']})).violations.flatMap(violation => violation.nodes.map(node => node.target)))()", []);
})->with(['light' => false, 'dark' => true]);
