<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

use Firefly\Admin\Tests\Support\Fixtures\AuditProperties;
use Firefly\Config\Scanner\ConfigPropertiesDescriptor;

/**
 * The dashboard capstone, given a Config properties page whose BOTH panels actually page.
 *
 * It is the Conditions page's problem one view over, and AdminTableConditionsPagedCapstoneTestCase is the
 * case it copies. Two qualified listings share this page and each one's links have to carry the other's
 * position, but the only caller of `ListingPage::link()` anywhere is `_pager`'s paged branch — so on the
 * bare table capstone, where three bound rows and one unbound one fit on every offered size, the branch
 * never renders and the carrying that `AdminAction::configPropsPage()` rebuilds each slice for is covered
 * by nothing. This case makes both panels page and then reads the hrefs.
 *
 * IT ALSO GIVES THE BOUND LISTING A SECOND DTO, which is a different claim and could not be made on the
 * parent either. The bound listing draws one row per PROPERTY across every DTO, so a tie in the ordered
 * column has to be broken by something unique per row — and with one bound class in the fixture, `class`
 * and `key` and the row's identity all agree, which is precisely how a tiebreak that ties gets committed.
 * AuditProperties shares a resolved VALUE with BillingProperties and disagrees with it about the order,
 * which is what makes the answer observable. See its docblock for the arithmetic.
 *
 * Page sizes are OFFERED through configOverrides() rather than set from a test body for the reason the
 * conditions case spells out: AdminSettings is built once during the boot passes and bound as an instance,
 * so a `config()->set()` from a test arrives after the object that would have read it.
 */
abstract class AdminTableConfigPropsPagedCapstoneTestCase extends AdminTableCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.admin.table.page-sizes' => '2,25,50',
        ];
    }

    /**
     * Six bound rows over two DTOs and three unbound ones — three pages and two pages at two a page.
     *
     * The two extra unbound descriptors name classes that do not exist, and that is not a shortcut:
     * `describe()` asks the container whether the class is bound and reports `bound: false` when it is not,
     * without ever reflecting on it, so a profile-gated DTO the application never loaded is EXACTLY this
     * row. The same reasoning lets the conditions case seed `App\Widgets\WidgetAutoConfiguration01`.
     *
     * @return list<ConfigPropertiesDescriptor>
     */
    protected function configProperties(): array
    {
        return [
            ...parent::configProperties(),
            new ConfigPropertiesDescriptor(AuditProperties::class, 'audit'),
            new ConfigPropertiesDescriptor('App\Reporting\ReportingProperties', 'reporting', ['production']),
            new ConfigPropertiesDescriptor('App\Shipping\ShippingProperties', 'shipping', ['staging']),
        ];
    }

    /** @return array<class-string, object> */
    protected function boundConfigProperties(): array
    {
        return [...parent::boundConfigProperties(), AuditProperties::class => new AuditProperties];
    }
}
