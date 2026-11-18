<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests\Filament;

use Bjanczak\BladeHugeicons\BladeHugeiconsServiceProvider;
use Bjanczak\FilamentFlexFields\FilamentFlexFieldsServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\FormsServiceProvider;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use UseFlex\MediaStudio\Integrations\Filament\StudioFileUpload;
use UseFlex\MediaStudio\Integrations\Filament\StudioMediaDisplay;
use UseFlex\MediaStudio\Integrations\Filament\StudioMediaPicker;
use UseFlex\MediaStudio\Integrations\Filament\StudioPicker;
use UseFlex\MediaStudio\Integrations\Filament\StudioReference;
use UseFlex\MediaStudio\Integrations\Filament\StudioReferenceSelection;
use UseFlex\MediaStudio\Integrations\FlexFields\StudioFileUpload as StudioFlexFileUpload;
use UseFlex\MediaStudio\Library\MediaReferences;
use UseFlex\MediaStudio\Tests\TestCase;

final class StudioFlexUploadTest extends TestCase
{
    public function test_source_controls_share_the_native_flex_button_and_upload_comes_first(): void
    {
        $workspace = $this->workspace();
        $folder = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Images'])->assertCreated()->json('id');
        $component = Livewire::test(StudioFlexUploadFixture::class, compact('workspace', 'folder'));
        $dom = new \DOMDocument;
        @$dom->loadHTML($component->html());
        $xpath = new \DOMXPath($dom);
        $buttons = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fff-flex-file-upload__source-controls ")]//button');
        $this->assertCount(2, $buttons);
        $this->assertSame('Upload file', trim($buttons->item(0)->textContent));
        $this->assertSame('Choose media', trim($buttons->item(1)->textContent));
        foreach ($buttons as $button) {
            $this->assertSame('fff-flex-file-upload__select', $button->getAttribute('class'));
            $this->assertSame(0, $button->getElementsByTagName('svg')->length);
        }
        $this->assertStringContainsString('mountAction', $buttons->item(1)->getAttribute('wire:click'));
    }

    public function test_deleted_files_are_removed_on_reload_and_before_save_for_all_studio_fields(): void
    {
        foreach (['flex', 'native', 'picker'] as $mode) {
            foreach ([true, false] as $deleteBeforeOpen) {
                $workspace = $this->workspace();
                $folder = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Destination'])->assertCreated()->json('id');
                $uploads = [];
                for ($i = 0; $i < 5; $i++) {
                    $uploads[] = $this->withHeader('Idempotency-Key', hash('sha256', "$mode-$deleteBeforeOpen-$i"))
                        ->postJson($this->url($workspace, 'uploads'), ['parent' => $folder,
                            'file' => UploadedFile::fake()->image("selected-$i.png", 48, 32)])
                        ->assertCreated()->json();
                }
                $initial = array_map(static fn (array $file): string => StudioReference::key(['assetId' => $file['id'], 'versionId' => $file['versionId']]), $uploads);
                $trash = function () use ($workspace, $uploads): void {
                    foreach (array_slice($uploads, 0, 2) as $file) {
                        $this->postJson($this->url($workspace, 'entries/'.$file['id'].'/trash'), ['revision' => 1])->assertOk();
                    }
                };
                if ($deleteBeforeOpen) {
                    $trash();
                }
                $component = Livewire::test(StudioFlexUploadFixture::class, compact('workspace', 'folder', 'initial', 'mode'));
                if ($deleteBeforeOpen) {
                    $this->assertSame(array_slice($initial, 2), array_values($component->get('data.files')));
                    $component->assertDontSee('selected-0.png')->assertDontSee('selected-1.png');
                } else {
                    $trash();
                }
                $component->call('save')->assertHasNoErrors()->assertSet('saved', array_slice($initial, 2));
                $component->assertDontSee('selected-0.png')->assertDontSee('selected-1.png');
                $this->assertSame(array_slice($initial, 2), array_values($component->get('data.files')));
                $this->assertSame(5, DB::table('fms_asset_versions as v')->join('fms_entries as e', 'e.id', '=', 'v.entry_id')
                    ->join('fms_workspaces as w', 'w.id', '=', 'e.workspace_id')->where('w.public_id', $workspace)->count());
                foreach (array_slice($uploads, 2) as $file) {
                    $this->postJson($this->url($workspace, 'entries/'.$file['id'].'/trash'), ['revision' => 1])->assertOk();
                }
                $component->call('save')->assertHasNoErrors()->assertSet('saved', [])
                    ->assertSet('data.files', []);
            }
        }
    }

    public function test_cleanup_does_not_hide_backend_failures_or_invalid_references(): void
    {
        $workspace = $this->workspace();
        $values = ['invalid-reference'];
        $this->assertSame($values, StudioReferenceSelection::available($workspace, $values));
        $upload = $this->withHeader('Idempotency-Key', hash('sha256', 'cleanup-failure'))
            ->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->image('kept.png')])
            ->assertCreated()->json();
        $values = [StudioReference::key(['assetId' => $upload['id'], 'versionId' => $upload['versionId']])];
        $this->app->bind(MediaReferences::class,
            static fn () => throw new \RuntimeException('Backend unavailable'));
        try {
            StudioReferenceSelection::available($workspace, $values);
            $this->fail('A backend failure must never be treated as a missing file.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Backend unavailable', $error->getMessage());
        }
        $this->assertCount(1, $values);
    }

    public function test_existing_selection_counts_toward_modal_limit_and_replacement_is_atomic(): void
    {
        $workspace = $this->workspace();
        $folder = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Photos'])->assertCreated()->json('id');
        $files = [];
        for ($i = 0; $i < 3; $i++) {
            $file = $this->withHeader('Idempotency-Key', hash('sha256', "picker-limit-$i"))
                ->postJson($this->url($workspace, 'uploads'), ['parent' => $folder,
                    'file' => UploadedFile::fake()->image("limit-$i.png")])->assertCreated()->json();
            $files[] = ['assetId' => $file['id'], 'versionId' => $file['versionId']];
        }
        $initial = array_map(StudioReference::key(...), array_slice($files, 0, 2));
        foreach (['flex', 'native', 'picker'] as $mode) {
            $component = Livewire::test(StudioFlexUploadFixture::class, [...compact('workspace', 'folder', 'mode', 'initial'),
                'limit' => 2, 'minimum' => 1, 'thumbnailPreset' => 'picker-card']);
            $action = $mode === 'picker' ? 'pickStudioMedia' : 'selectStudioFile';
            $key = $mode === 'picker' ? 'references' : 'reference';
            $component->call('mountAction', $action, [], ['schemaComponent' => 'form.files'])->assertHasNoErrors();
            $this->assertSame(array_slice($files, 0, 2), $component->get('mountedActions.0.data.'.$key));
            $options = $component->instance()->getMountedAction()->getSchema(Schema::make($component->instance()))->getComponents()[0]->getStudioOptions();
            $this->assertSame(2, $options['selectionLimit']);
            $this->assertSame(array_slice($files, 0, 2), $options['pickerInitialSelection']);
            $this->assertSame(['image/png'], $options['pickerMimeTypes']);
            $this->assertSame('picker-card', $options['pickerThumbnailPreset']);
            $component->set('mountedActions.0.data.'.$key, [$files[2]])->call('callMountedAction')->assertHasNoErrors()
                ->call('save')->assertHasNoErrors()->assertSet('saved', [StudioReference::key($files[2])]);
            $field = $component->instance()->form->getComponents()[0];
            if ($mode !== 'picker') {
                foreach ($field->getUploadedFiles() as $info) {
                    $this->assertNull($info['url'], 'Missing thumbnails must never use the original.');
                }
            } else {
                $this->assertNull($field->getSelectedStudioMedia()[0]['thumbnailUrl']);
            }
            $component->call('mountAction', $action, [], ['schemaComponent' => 'form.files'])
                ->set('mountedActions.0.data.'.$key, $files)->call('callMountedAction')->assertHasErrors();
            $this->assertSame([StudioReference::key($files[2])], array_values($component->get('data.files')));
        }
    }

    public function test_mime_patterns_and_missing_thumbnail_never_fall_back_to_original(): void
    {
        $picker = StudioPicker::make('choice')
            ->acceptedMimeTypes(['image/*', 'application/pdf'])->thumbnailPreset('tiny-card');
        $this->assertSame(['image/*', 'application/pdf'], $picker->getStudioOptions()['pickerMimeTypes']);
        $this->assertTrue($picker::matchesMime('image/webp', ['image/*']));
        $this->assertFalse($picker::matchesMime('video/mp4', ['image/*']));
        $this->assertNull(StudioMediaDisplay::image([
            'thumbnailUrl' => null, 'url' => 'https://example.test/original.png', 'mimeType' => 'image/png', 'sizeBytes' => 20,
        ]));
    }

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), LivewireServiceProvider::class,
            BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class,
            BladeHugeiconsServiceProvider::class, SupportServiceProvider::class,
            ActionsServiceProvider::class, SchemasServiceProvider::class,
            FormsServiceProvider::class, FilamentFlexFieldsServiceProvider::class];
    }

    public function test_flex_upload_publishes_into_studio_folder_and_returns_versioned_reference(): void
    {
        $workspace = $this->workspace();
        $folder = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Destination'])->assertCreated()->json('id');
        $component = Livewire::test(StudioFlexUploadFixture::class, compact('workspace', 'folder'))
            ->assertSee('Choose media')->assertSee('Upload file')
            ->set('data.files', [UploadedFile::fake()->image('flex-studio.png', 48, 32)])
            ->assertHasNoErrors();
        $values = array_values($component->get('data.files'));
        $this->assertCount(1, $values);
        $reference = StudioReference::parse($values[0]);
        $this->assertNotNull($reference);
        $entry = DB::table('fms_entries')->where('public_id', $reference['assetId'])->first();
        $this->assertSame($folder, DB::table('fms_entries')->where('id', $entry->parent_id)->value('public_id'));
        $this->assertSame($reference['versionId'], $entry->current_version_id);
        $component->call('save')->assertHasNoErrors()->assertSet('saved', [$values[0]]);
        $this->assertDatabaseCount('fms_asset_versions', 1);
    }

    public function test_flex_chooses_existing_file_without_copying_it_and_rejects_outside_scope(): void
    {
        $workspace = $this->workspace();
        $folder = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Allowed'])->assertCreated()->json('id');
        $upload = fn (?string $parent, string $name) => $this->withHeader('Idempotency-Key', hash('sha256', $name))
            ->postJson($this->url($workspace, 'uploads'), ['parent' => $parent, 'file' => UploadedFile::fake()->image($name)])
            ->assertCreated()->json();
        $inside = $upload($folder, 'inside.png');
        $outside = $upload(null, 'outside.png');
        $ref = static fn (array $file): array => ['workspaceId' => $workspace, 'assetId' => $file['id'], 'versionId' => $file['versionId']];
        Livewire::test(StudioFlexUploadFixture::class, compact('workspace', 'folder'))
            ->call('mountAction', 'selectStudioFile', [], ['schemaComponent' => 'form.files'])
            ->set('mountedActions.0.data.reference', [$ref($inside)])
            ->call('callMountedAction')->assertHasNoErrors()
            ->call('save')->assertHasNoErrors()->assertSet('saved', [StudioReference::key($ref($inside))])
            ->call('mountAction', 'selectStudioFile', [], ['schemaComponent' => 'form.files'])
            ->set('mountedActions.0.data.reference', [$ref($outside)])
            ->call('callMountedAction')->assertHasErrors();
        $this->assertDatabaseCount('fms_asset_versions', 2);
    }

    public function test_flex_rejects_non_images_before_they_enter_studio(): void
    {
        $workspace = $this->workspace();
        $folder = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Images'])->assertCreated()->json('id');
        Livewire::test(StudioFlexUploadFixture::class, compact('workspace', 'folder'))
            ->set('data.files', [UploadedFile::fake()->create('notes.txt', 1, 'text/plain')])
            ->assertHasErrors('data.files');
        $this->assertDatabaseCount('fms_asset_versions', 0);
    }

    #[DataProvider('replacementPolicies')]
    public function test_classic_image_edit_keeps_the_stored_reference_until_completion(bool $deleteReplacedFiles): void
    {
        $workspace = $this->workspace();
        $folder = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Images'])->assertCreated()->json('id');
        $upload = function (string $name) use ($workspace, $folder): string {
            $file = $this->withHeader('Idempotency-Key', hash('sha256', $name))
                ->postJson($this->url($workspace, 'uploads'), ['parent' => $folder, 'file' => UploadedFile::fake()->image($name)])
                ->assertCreated()->json();

            return StudioReference::key(['assetId' => $file['id'], 'versionId' => $file['versionId']]);
        };
        $original = $upload('original.png');
        $replacement = $upload('edited.png');
        $sibling = $upload('untouched.png');
        $component = Livewire::test(StudioFlexUploadFixture::class, [
            'workspace' => $workspace, 'folder' => $folder, 'initial' => [$original, $sibling],
            'limit' => 2, 'replacement' => $replacement, 'deleteReplacedFiles' => $deleteReplacedFiles,
        ]);
        [$oldKey, $siblingKey] = array_keys($component->get('data.files'));
        $newKey = 'fff-editor-'.Str::uuid();
        $field = $component->instance()->form->getComponents()[0];

        $source = $field->getImageEditorSource($oldKey);
        $this->assertSame('original.png', $source['name']);
        $this->assertStringContainsString(StudioReference::parse($original)['versionId'], $source['url']);
        $dom = new \DOMDocument;
        @$dom->loadHTML($component->html());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(2, $xpath->query('//div[contains(@class, "fff-flex-file-upload__tile-primary")]/button[@aria-haspopup="menu" and @disabled]')->length);

        $component->set('data.files.'.$newKey, UploadedFile::fake()->image('edited.png', 32, 48))
            ->assertHasNoErrors()->assertSet('data.files.'.$oldKey, $original);
        $this->assertDatabaseCount('fms_asset_versions', 3);

        $component->call('callSchemaComponentMethod', $field->getKey(), 'completeImageEditorReplacement', [
            'fileKey' => $newKey, 'replacedKey' => $oldKey,
        ])->assertHasNoErrors()->assertSet('data.files', [$newKey => $replacement, $siblingKey => $sibling])
            ->assertSet('publishedDeletePreviousVersion', $deleteReplacedFiles);
        $component->call('callSchemaComponentMethod', $field->getKey(), 'getUploadedFiles', [])
            ->assertReturned(fn (?array $files): bool => array_map(fn (array $file): string => $file['name'], $files ?? []) === [
                $newKey => 'edited.png', $siblingKey => 'untouched.png',
            ]);
        $component->call('save')->assertSet('saved', [$replacement, $sibling]);
        $this->assertDatabaseCount('fms_asset_versions', 3);
    }

    public static function replacementPolicies(): array
    {
        return ['preserve history' => [false], 'remove replaced file' => [true]];
    }
}

final class StudioFlexUploadFixture extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    #[Locked]
    public string $workspace;

    #[Locked]
    public string $folder;

    #[Locked]
    public string $mode = 'flex';

    #[Locked]
    public int $limit = 8;

    #[Locked]
    public int $minimum = 0;

    #[Locked]
    public ?string $thumbnailPreset = null;

    #[Locked]
    public ?string $replacement = null;

    #[Locked]
    public bool $deleteReplacedFiles = false;

    public ?bool $publishedDeletePreviousVersion = null;

    public array $data = [];

    public array $saved = [];

    public function mount(string $workspace, string $folder, array $initial = [], string $mode = 'flex', int $limit = 8, int $minimum = 0, ?string $thumbnailPreset = null, ?string $replacement = null, bool $deleteReplacedFiles = false): void
    {
        $this->workspace = $workspace;
        $this->folder = $folder;
        $this->mode = $mode;
        $this->limit = $limit;
        $this->minimum = $minimum;
        $this->thumbnailPreset = $thumbnailPreset;
        $this->replacement = $replacement;
        $this->deleteReplacedFiles = $deleteReplacedFiles;
        $this->form->fill(['files' => $initial]);
    }

    public function form(Schema $schema): Schema
    {
        if ($this->mode === 'picker') {
            return $schema->statePath('data')->components([
                StudioMediaPicker::make('files')
                    ->studio($this->workspace, '/studio.js')->multiple()->maxFiles($this->limit)->minFiles($this->minimum)->studioThumbnailPreset($this->thumbnailPreset)
                    ->acceptedFileTypes(['image/png'])->scopedFolder($this->folder),
            ]);
        }
        $upload = $this->mode === 'native'
            ? StudioFileUpload::class
            : StudioFlexFileUpload::class;

        $field = $upload::make('files')->label('Studio · FLEX Fields')
            ->studio(fn (): string => $this->workspace, '/studio.js', uploadFolder: fn (): string => $this->folder,
                acceptedMimeTypes: ['image/png'])
            ->studioScopedFolder(fn (): string => $this->folder)
            ->multiple()->maxFiles($this->limit)->minFiles($this->minimum)->studioThumbnailPreset($this->thumbnailPreset)->reorderable();
        if ($field instanceof StudioFlexFileUpload && $this->replacement !== null) {
            $field->uploadLayout('grid')->openable()
                ->deleteReplacedFiles(fn (): bool => $this->deleteReplacedFiles)
                ->studioImageEditor(function (bool $deletePreviousVersion): string {
                    $this->publishedDeletePreviousVersion = $deletePreviousVersion;

                    return $this->replacement;
                });
        }

        return $schema->statePath('data')->components([$field]);
    }

    public function save(): void
    {
        $this->saved = $this->form->getState()['files'] ?? [];
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}<x-filament-actions::modals /></div>';
    }
}
