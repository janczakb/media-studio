<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests\Filament;

use Bjanczak\FilamentFlexFields\Filament\Forms\Components\FlexRichEditor;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\RichEditor\TipTapExtensions\ImageExtension;
use Filament\Forms\FormsServiceProvider;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tiptap\Editor;
use Tiptap\Extensions\StarterKit;
use UseFlex\MediaStudio\Integrations\Filament\MarkdownStudio;
use UseFlex\MediaStudio\Integrations\Filament\ResponsiveImages;
use UseFlex\MediaStudio\Integrations\Filament\RichEditorStudioPlugin;
use UseFlex\MediaStudio\Integrations\Filament\StudioMediaPicker;
use UseFlex\MediaStudio\Integrations\Filament\StudioPicker;
use UseFlex\MediaStudio\Integrations\Filament\StudioReference;
use UseFlex\MediaStudio\Tests\TestCase;

final class RichEditorStudioPluginTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            SchemasServiceProvider::class,
            FormsServiceProvider::class,
        ];
    }

    public function test_native_action_renders_picker_and_dispatches_an_image_at_the_saved_selection(): void
    {
        $workspace = $this->workspace();
        $image = $this->withHeader('Idempotency-Key', 'studio-editor-'.bin2hex(random_bytes(8)))->postJson($this->url($workspace, 'uploads'), [
            'file' => UploadedFile::fake()->image('studio.png'),
        ])->assertCreated()->json();
        $selection = ['type' => 'text', 'anchor' => 3, 'head' => 5];

        $editor = Livewire::test(StudioRichEditorFixture::class, ['workspace' => $workspace])
            ->assertSeeHtml('data-rich-editor-tool="insertStudioMedia"')
            ->call('mountAction', RichEditorStudioPlugin::TOOL, ['editorSelection' => $selection], ['schemaComponent' => 'form.body'])
            ->assertSee('Media Studio');
        $editor->call('forceRender')
            ->assertSee('Insert media')
            ->assertSeeHtml('mountMediaStudio')
            ->set('mountedActions.0.data.reference', ['assetId' => $image['id'], 'versionId' => $image['versionId']])
            ->set('mountedActions.0.data.alt', 'Studio cover')
            ->call('callMountedAction')
            ->assertHasNoErrors()
            ->assertDispatched('run-rich-editor-commands', function (string $event, array $payload) use ($workspace, $image, $selection): bool {
                $node = $payload['commands'][0]['arguments'][0];

                return $payload['editorSelection'] === $selection
                    && $payload['commands'][0]['name'] === 'insertContent'
                    && $node === ['type' => 'image', 'attrs' => [
                        'src' => url($this->url($workspace, 'entries/'.$image['id'].'/versions/'.$image['versionId'].'/content')),
                        'alt' => 'Studio cover',
                    ]];
            });
    }

    public function test_native_editor_actions_reject_a_forged_non_image_reference(): void
    {
        $workspace = $this->workspace();
        $file = $this->withHeader('Idempotency-Key', 'editor-forged-non-image')->postJson($this->url($workspace, 'uploads'), [
            'file' => UploadedFile::fake()->createWithContent('brief.txt', 'Text attachment'),
        ])->assertCreated()->json();

        foreach (['body' => ['editorSelection' => ['type' => 'all']], 'markdown' => []] as $field => $args) {
            Livewire::test(StudioRichEditorFixture::class, ['workspace' => $workspace])
                ->call('mountAction', RichEditorStudioPlugin::TOOL, $args, ['schemaComponent' => 'form.'.$field])
                ->set('mountedActions.0.data.reference', ['assetId' => $file['id'], 'versionId' => $file['versionId']])
                ->call('callMountedAction')
                ->assertStatus(422);
        }
    }

    public function test_markdown_action_targets_its_editor_and_escapes_the_image_label(): void
    {
        $workspace = $this->workspace();
        $image = $this->withHeader('Idempotency-Key', 'studio-markdown-test')->postJson($this->url($workspace, 'uploads'), [
            'file' => UploadedFile::fake()->image('studio.png'),
        ])->assertCreated()->json();

        Livewire::test(StudioRichEditorFixture::class, ['workspace' => $workspace])
            ->assertSeeHtml('x-on:click.capture')
            ->assertSeeHtml('changeGeneration(true)')
            ->assertSeeHtml('x-on:media-studio-insert-markdown.window')
            ->call('mountAction', RichEditorStudioPlugin::TOOL, [], ['schemaComponent' => 'form.markdown'])
            ->call('forceRender')
            ->assertSee('Insert media')
            ->set('mountedActions.0.data.reference', ['assetId' => $image['id'], 'versionId' => $image['versionId']])
            ->set('mountedActions.0.data.alt', 'Image [a] *b*')
            ->call('callMountedAction')
            ->assertHasNoErrors()
            ->assertDispatched('media-studio-insert-markdown', function (string $event, array $payload) use ($workspace, $image): bool {
                $url = url($this->url($workspace, 'entries/'.$image['id'].'/versions/'.$image['versionId'].'/content'));

                return $payload['editorId'] === 'form.markdown'
                    && $payload['markdown'] === '![Image \[a\] \*b\*]('.$url.')';
            });
    }

    public function test_both_native_editors_insert_the_selected_generated_variant(): void
    {
        $workspace = $this->workspace();
        $image = $this->withHeader('Idempotency-Key', 'studio-editor-variant-test')->postJson($this->url($workspace, 'uploads'), [
            'file' => UploadedFile::fake()->image('variant.png', 400, 200),
        ])->assertCreated()->json();
        $selection = ['type' => 'text', 'anchor' => 2, 'head' => 2];
        $reference = ['assetId' => $image['id'], 'versionId' => $image['versionId']];
        $url = url($this->url($workspace, 'entries/'.$image['id'].'/versions/'.$image['versionId'].'/renditions/thumbnail-v1'));
        Livewire::test(StudioRichEditorFixture::class, ['workspace' => $workspace])
            ->call('mountAction', RichEditorStudioPlugin::TOOL, ['editorSelection' => $selection], ['schemaComponent' => 'form.body'])
            ->set('mountedActions.0.data.reference', $reference)
            ->set('mountedActions.0.data.rendition', 'thumbnail-v1')
            ->call('callMountedAction')->assertHasNoErrors()
            ->assertDispatched('run-rich-editor-commands', function (string $event, array $payload) use ($url): bool {
                return $payload['commands'][0]['arguments'][0]['attrs']['src'] === $url;
            });
        Livewire::test(StudioRichEditorFixture::class, ['workspace' => $workspace])
            ->call('mountAction', RichEditorStudioPlugin::TOOL, [], ['schemaComponent' => 'form.markdown'])
            ->set('mountedActions.0.data.reference', $reference)
            ->set('mountedActions.0.data.rendition', 'thumbnail-v1')
            ->set('mountedActions.0.data.alt', 'Small cover')
            ->call('callMountedAction')->assertHasNoErrors()
            ->assertDispatched('media-studio-insert-markdown', fn (string $event, array $payload): bool => $payload['markdown'] === '![Small cover]('.$url.')');
        $this->get($url)->assertOk();
    }

    public function test_scoped_picker_resolves_the_selected_workspace_and_rejects_outside_scope(): void
    {
        $first = $this->workspace('First');
        $second = $this->workspace('Second');
        $image = $this->withHeader('Idempotency-Key', 'multi-workspace-editor')->postJson($this->url($second, 'uploads'), [
            'file' => UploadedFile::fake()->image('scope.png'),
        ])->assertCreated()->json();
        $data = ['reference' => ['workspaceId' => $second, 'assetId' => $image['id'], 'versionId' => $image['versionId']]];
        $this->assertSame($image['id'], StudioPicker::resolveSelection([$first, $second], $data)['assetId']);
        $this->expectException(HttpException::class);
        StudioPicker::resolveSelection($first, $data);
    }

    public function test_responsive_image_attributes_survive_the_php_editor_round_trip(): void
    {
        $workspace = $this->workspace();
        $image = $this->withHeader('Idempotency-Key', 'responsive-editor')->postJson($this->url($workspace, 'uploads'), [
            'file' => UploadedFile::fake()->image('responsive.png', 1600, 900),
        ])->assertCreated()->json();
        $data = ['reference' => ['assetId' => $image['id'], 'versionId' => $image['versionId']]];
        $file = StudioPicker::resolveSelection($workspace, $data);
        $attrs = StudioPicker::responsiveAttributes($workspace, $data, $file);
        $this->assertStringContainsString('w, ', $attrs['srcset']);
        $editor = new Editor(['extensions' => [new StarterKit, new ImageExtension, new ResponsiveImages]]);
        $html = $editor->setContent(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => $attrs]]]]])->getHTML();
        $this->assertStringContainsString('srcset=', $html);
        $this->assertStringContainsString('sizes=', $html);
    }

    public function test_picker_rejects_a_variant_removed_after_selection(): void
    {
        $workspace = $this->workspace();
        $image = $this->withHeader('Idempotency-Key', 'studio-editor-stale-variant')->postJson($this->url($workspace, 'uploads'), [
            'file' => UploadedFile::fake()->image('variant.png'),
        ])->assertCreated()->json();
        DB::table('fms_renditions')->where('version_id', $image['versionId'])->update(['state' => 'unavailable', 'replacement_recipe' => null]);
        $this->expectException(HttpException::class);
        StudioPicker::resolveSelection($workspace, [
            'reference' => ['assetId' => $image['id'], 'versionId' => $image['versionId']], 'rendition' => 'thumbnail-v1',
        ]);
    }

    public function test_actual_flex_editor_accepts_plugin_commands_without_reuploading_media(): void
    {
        $source = getenv('FLEX_FIELDS_SOURCE');
        if (is_string($source) && is_file($source.'/src/Filament/Forms/Components/FlexRichEditor.php')) {
            $loader = require dirname(__DIR__).'/vendor/autoload.php';
            $loader->addPsr4('Bjanczak\\FilamentFlexFields\\', $source.'/src/');
        }
        if (! class_exists(FlexRichEditor::class)) {
            $this->markTestSkipped('Set FLEX_FIELDS_SOURCE to test the real optional FLEX editor server contract.');
        }
        $workspace = $this->workspace();
        $image = $this->withHeader('Idempotency-Key', 'studio-flex-editor-contract')->postJson($this->url($workspace, 'uploads'), [
            'file' => UploadedFile::fake()->image('flex.png'),
        ])->assertCreated()->json();
        $versions = DB::table('fms_asset_versions')->count();
        Livewire::test(StudioFlexEditorFixture::class, ['workspace' => $workspace])
            ->call('mountAction', RichEditorStudioPlugin::TOOL, ['editorSelection' => ['type' => 'text', 'anchor' => 1, 'head' => 1]], ['schemaComponent' => 'form.body'])
            ->set('mountedActions.0.data.reference', ['assetId' => $image['id'], 'versionId' => $image['versionId']])
            ->set('mountedActions.0.data.rendition', 'thumbnail-v1')
            ->call('callMountedAction')->assertHasNoErrors()
            ->assertDispatched('run-rich-editor-commands', fn (string $event, array $payload): bool => $payload['commands'][0]['arguments'][0]['type'] === 'image');
        $this->assertSame($versions, DB::table('fms_asset_versions')->count());
    }

    public function test_attachment_ids_regenerate_authenticated_version_urls_and_reject_other_workspaces(): void
    {
        $workspace = $this->workspace();
        $other = $this->workspace('Outside');
        $file = $this->withHeader('Idempotency-Key', 'rich-attachment-provider-test')->postJson($this->url($workspace, 'uploads'), [
            'file' => UploadedFile::fake()->image('pinned.png'),
        ])->assertCreated()->json();
        $plugin = new RichEditorStudioPlugin($workspace, '/studio.js', persistAttachmentIds: true);
        $id = $workspace.':'.$file['id'].':'.$file['versionId'];
        $url = url($this->url($workspace, 'entries/'.$file['id'].'/versions/'.$file['versionId'].'/content'));

        $renderer = RichContentRenderer::make('<img data-id="'.$id.'" src="https://expired.example.test/image.png" alt="Pinned image">')
            ->plugins([$plugin]);
        $this->assertStringContainsString($url, $renderer->toHtml());
        $this->assertStringNotContainsString('expired.example.test', $renderer->toHtml());
        $this->assertNull($plugin->getFileAttachmentProvider()->getFileAttachmentUrl($other.':'.$file['id'].':'.$file['versionId']));
    }

    public function test_media_picker_orders_pinned_references_and_rejects_files_outside_its_folder_scope(): void
    {
        $workspace = $this->workspace();
        $folder = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Gallery'])->assertCreated()->json('id');
        $child = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Subfolder', 'parent' => $folder])->assertCreated()->json('id');
        $upload = fn (string $name, ?string $parent) => $this->withHeader('Idempotency-Key', 'picker-component-'.hash('sha256', $name))
            ->postJson($this->url($workspace, 'uploads'), ['parent' => $parent, 'file' => UploadedFile::fake()->image($name)])
            ->assertCreated()->json();
        $first = $upload('first.png', $folder);
        $second = $upload('second.png', $child);
        $outside = $upload('outside.png', null);
        $ref = fn (array $item): array => ['assetId' => $item['id'], 'versionId' => $item['versionId']];
        $firstKey = StudioReference::key($ref($first));
        $secondKey = StudioReference::key($ref($second));

        Livewire::test(StudioMediaPickerFixture::class, ['workspace' => $workspace, 'folder' => $folder])
            ->call('mountAction', 'pickStudioMedia', [], ['schemaComponent' => 'form.gallery'])
            ->set('mountedActions.0.data.references', [$ref($first), $ref($second)])
            ->call('callMountedAction')
            ->assertHasNoErrors()->assertSet('data.gallery', [$firstKey, $secondKey])
            ->assertSeeHtml('data-layout="grid"')
            ->assertDontSeeHtml('fms-filament-picker__layouts')
            ->call('mountAction', 'inspectFile', ['mediaReference' => $secondKey], ['schemaComponent' => 'form.gallery'])
            ->call('callMountedAction')
            ->assertHasNoErrors()->assertSet('inspectedFile', 'second.png')
            ->call('callSchemaComponentMethod', 'form.gallery', 'moveStudioMedia', [1, -1])
            ->assertSet('data.gallery', [$secondKey, $firstKey])
            ->call('callSchemaComponentMethod', 'form.gallery', 'removeStudioMedia', [1])
            ->assertSet('data.gallery', [$secondKey])
            ->call('mountAction', 'pickStudioMedia', [], ['schemaComponent' => 'form.gallery'])
            ->set('mountedActions.0.data.references', [$ref($outside)])
            ->call('callMountedAction')
            ->assertHasErrors()->assertSet('data.gallery', [$secondKey]);
        Livewire::test(StudioMediaPickerFixture::class, ['workspace' => $workspace, 'folder' => $folder])
            ->set('data.gallery', [$firstKey])
            ->call('mountAction', 'inspectFile', ['mediaReference' => $secondKey], ['schemaComponent' => 'form.gallery'])
            ->call('callMountedAction')
            ->assertStatus(404)->assertSet('inspectedFile', null);
        $this->assertDatabaseCount('fms_asset_versions', 3);
    }

    public function test_media_picker_layout_and_switcher_accept_dynamic_configuration(): void
    {
        $picker = StudioMediaPicker::make('gallery');
        $this->assertSame('list', $picker->getStudioLayout());
        $this->assertTrue($picker->hasStudioLayoutSwitcher());
        $this->assertFalse($picker->isStudioDownloadable());
        $picker->downloadable(fn (): bool => true);
        $this->assertTrue($picker->isStudioDownloadable());
        $picker->downloadable(false);
        $this->assertFalse($picker->isStudioDownloadable());

        $picker->layout(fn (): string => 'grid')->layoutSwitcher(fn (): bool => false);
        $this->assertSame('grid', $picker->getStudioLayout());
        $this->assertFalse($picker->hasStudioLayoutSwitcher());

        $picker->layout('list')->layoutSwitcher();
        $this->assertSame('list', $picker->getStudioLayout());
        $this->assertTrue($picker->hasStudioLayoutSwitcher());
    }

    public function test_native_action_rejects_a_reference_from_another_workspace(): void
    {
        $workspace = $this->workspace();
        $other = $this->workspace('Other');
        $file = $this->withHeader('Idempotency-Key', 'studio-editor-'.bin2hex(random_bytes(8)))->postJson($this->url($other, 'uploads'), [
            'file' => UploadedFile::fake()->image('other.png'),
        ])->assertCreated()->json();

        Livewire::test(StudioRichEditorFixture::class, ['workspace' => $workspace])
            ->call('mountAction', RichEditorStudioPlugin::TOOL, ['editorSelection' => ['type' => 'text', 'anchor' => 1, 'head' => 1]], ['schemaComponent' => 'form.body'])
            ->set('mountedActions.0.data.reference', ['assetId' => $file['id'], 'versionId' => $file['versionId']])
            ->call('callMountedAction')
            ->assertStatus(404);
    }
}

class StudioRichEditorFixture extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    #[Locked]
    public string $workspace;

    public array $data = [];

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            RichEditor::make('body')
                ->plugins([new RichEditorStudioPlugin($this->workspace, '/studio.js')])
                ->toolbarButtons([[RichEditorStudioPlugin::TOOL]]),
            MarkdownStudio::attach(MarkdownEditor::make('markdown'), $this->workspace, '/studio.js'),
        ]);
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}<x-filament-actions::modals /></div>';
    }
}

final class StudioFlexEditorFixture extends StudioRichEditorFixture
{
    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            FlexRichEditor::make('body')
                ->plugins([new RichEditorStudioPlugin($this->workspace, '/studio.js')])
                ->toolbarButtons([[RichEditorStudioPlugin::TOOL]]),
        ]);
    }

    public function render(): string
    {
        // Exercise real field/action commands; browser rendering is a separate acceptance gate.
        return '<div></div>';
    }
}

final class StudioMediaPickerFixture extends StudioRichEditorFixture
{
    #[Locked]
    public string $folder;

    public ?string $inspectedFile = null;

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            StudioMediaPicker::make('gallery')->studio($this->workspace, '/studio.js')
                ->multiple()->maxFiles(3)->reorderable()->acceptedFileTypes(['image/png'])->scopedFolder($this->folder)
                ->layout(fn (): string => 'grid')->layoutSwitcher(fn (): bool => false)
                ->fileActions([
                    \Filament\Actions\Action::make('inspectFile')->label('Inspect')->icon('heroicon-o-eye')->iconButton()
                        ->action(function (array $file, Component $livewire): void {
                            $livewire->inspectedFile = $file['name'];
                        }),
                ]),
        ]);
    }
}
