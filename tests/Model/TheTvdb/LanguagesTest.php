<?php

namespace Tests\Model\TheTvdb;

use Api\Model\TheTvdb\Languages;
use PHPUnit\Framework\TestCase;

final class LanguagesTest extends TestCase
{

    public function testIdForCultureResolvesEveryKnownCulture(): void
    {
        $this->assertSame(1, Languages::idForCulture('ca'));
        $this->assertSame(2, Languages::idForCulture('es'));
        $this->assertSame(3, Languages::idForCulture('en'));
    }

    public function testIdForCultureReturnsNullForAnUnknownCulture(): void
    {
        $this->assertNull(Languages::idForCulture('fr'));
    }

    public function testCultureForIdRoundTripsWithIdForCulture(): void
    {
        $this->assertSame('ca', Languages::cultureForId(1));
        $this->assertSame('es', Languages::cultureForId(2));
        $this->assertSame('en', Languages::cultureForId(3));
        $this->assertNull(Languages::cultureForId(99));
    }

    public function testTvdbCodeMapsToTheThreeLetterCode(): void
    {
        $this->assertSame('cat', Languages::tvdbCode(1));
        $this->assertSame('spa', Languages::tvdbCode(2));
        $this->assertSame('eng', Languages::tvdbCode(3));
        $this->assertNull(Languages::tvdbCode(99));
    }

    public function testTvdbCodeForCultureChainsIdForCultureAndTvdbCode(): void
    {
        $this->assertSame('cat', Languages::tvdbCodeForCulture('ca'));
        $this->assertNull(Languages::tvdbCodeForCulture('fr'));
    }

    public function testTvdbCountryForCulture(): void
    {
        $this->assertSame('esp', Languages::tvdbCountryForCulture('ca'));
        $this->assertSame('esp', Languages::tvdbCountryForCulture('es'));
        $this->assertSame('usa', Languages::tvdbCountryForCulture('en'));
        $this->assertNull(Languages::tvdbCountryForCulture('fr'));
    }

}
