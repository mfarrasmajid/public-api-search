<?php

namespace Tests\Unit;

use App\Models\Api;
use App\Services\ApiImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_is_idempotent_on_slug(): void
    {
        $records = [['name' => 'Weather API', 'category' => 'Weather', 'provider' => 'ACME']];

        $first = app(ApiImporter::class)->import($records);
        $second = app(ApiImporter::class)->import($records);

        $this->assertSame(1, $first['created']);
        $this->assertSame(1, $second['updated']);
        $this->assertSame(1, Api::count());
    }

    public function test_authentication_values_are_normalised(): void
    {
        app(ApiImporter::class)->import([
            ['name' => 'A', 'authentication_type' => 'X-Mashape-Key'],
            ['name' => 'B', 'authentication_type' => 'OAuth 2.0'],
            ['name' => 'C', 'authentication_type' => ''],
        ]);

        $this->assertSame('apiKey', Api::where('slug', 'a')->value('authentication_type'));
        $this->assertSame('OAuth', Api::where('slug', 'b')->value('authentication_type'));
        $this->assertSame('none', Api::where('slug', 'c')->value('authentication_type'));
    }
}
