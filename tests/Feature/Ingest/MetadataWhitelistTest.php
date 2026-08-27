<?php

namespace Tests\Feature\Ingest;

use App\Models\Project;
use App\Models\ProjectMetaField;
use App\Models\SiteReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostsTelemetry;
use Tests\TestCase;

/**
 * add_extra() lets an integrator send anything at all, which makes it the
 * likeliest route by which data we never asked for -- including personal
 * data -- would arrive. The whitelist is the control.
 */
class MetadataWhitelistTest extends TestCase
{
    use PostsTelemetry, RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
    }

    protected function register(string $key, string $datatype): void
    {
        ProjectMetaField::acrossAccounts()->create([
            'account_id' => $this->project->account_id,
            'project_id' => $this->project->id,
            'key' => $key,
            'datatype' => $datatype,
        ]);
    }

    public function test_only_registered_keys_are_stored(): void
    {
        $this->register('pro_version', ProjectMetaField::TYPE_STRING);

        $this->track($this->project, ['extra' => [
            'pro_version' => '1.4.0',
            'customer_email' => 'someone@example.com',
        ]])->assertOk();

        $extra = SiteReport::acrossAccounts()->sole()->extra;

        $this->assertSame(['pro_version' => '1.4.0'], $extra);
    }

    /**
     * Form encoding makes every value a string. Without the declared type
     * a chart would be summing "12" as text and treating "0" as true.
     */
    public function test_values_are_cast_to_their_declared_type(): void
    {
        $this->register('seats', ProjectMetaField::TYPE_INTEGER);
        $this->register('is_pro', ProjectMetaField::TYPE_BOOLEAN);
        $this->register('score', ProjectMetaField::TYPE_FLOAT);

        $this->track($this->project, ['extra' => [
            'seats' => '12',
            'is_pro' => '0',
            'score' => '4.5',
        ]])->assertOk();

        $extra = SiteReport::acrossAccounts()->sole()->extra;

        $this->assertSame(12, $extra['seats']);
        $this->assertFalse($extra['is_pro']);
        $this->assertSame(4.5, $extra['score']);
    }

    public function test_a_project_with_no_whitelist_stores_nothing(): void
    {
        $this->track($this->project, ['extra' => ['anything' => 'at all']])->assertOk();

        $this->assertNull(SiteReport::acrossAccounts()->sole()->extra);
    }
}
