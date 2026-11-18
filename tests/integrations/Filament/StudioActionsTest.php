<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests\Filament;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Panel;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\TablesServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Integrations\Filament\Actions\ActionSnapshot;
use UseFlex\MediaStudio\Integrations\Filament\Actions\CreateFolderAction;
use UseFlex\MediaStudio\Integrations\Filament\Actions\DownloadAction;
use UseFlex\MediaStudio\Integrations\Filament\Actions\MoveAction;
use UseFlex\MediaStudio\Integrations\Filament\Actions\MoveBulkAction;
use UseFlex\MediaStudio\Integrations\Filament\Actions\RenameAction;
use UseFlex\MediaStudio\Integrations\Filament\Actions\RestoreAction;
use UseFlex\MediaStudio\Integrations\Filament\Actions\RestoreBulkAction;
use UseFlex\MediaStudio\Integrations\Filament\Actions\SelectFileAction;
use UseFlex\MediaStudio\Integrations\Filament\Actions\TrashAction;
use UseFlex\MediaStudio\Integrations\Filament\Actions\TrashBulkAction;
use UseFlex\MediaStudio\Tests\TestCase;

final class StudioActionsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), LivewireServiceProvider::class,
            BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class, SupportServiceProvider::class,
            ActionsServiceProvider::class, SchemasServiceProvider::class, FormsServiceProvider::class, TablesServiceProvider::class, FilamentServiceProvider::class];
    }

    private function file(string $workspace): array
    {
        return $this->withHeader('Idempotency-Key', 'native-actions-'.bin2hex(random_bytes(8)))
            ->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'Studio original')])
            ->assertCreated()->json();
    }

    public function test_native_actions_create_rename_move_trash_restore_and_download(): void
    {
        $workspace = $this->workspace();
        $file = $this->file($workspace);
        $fixture = Livewire::test(StudioActionFixture::class, ['workspace' => $workspace, 'entryId' => $file['id']]);
        $fixture->call('mountAction', 'create')->call('forceRender')
            ->assertSee('New folder')->set('mountedActions.0.data.name', 'Campaign')
            ->call('callMountedAction')->assertHasNoErrors();
        $folder = DB::table('fms_entries')->where('name', 'Campaign')->first();
        $this->assertNotNull($folder);

        $fixture->call('mountAction', 'rename')->call('forceRender')
            ->set('mountedActions.0.data.name', 'renamed.txt')
            ->call('callMountedAction')->assertHasNoErrors();
        $this->assertDatabaseHas('fms_entries', ['public_id' => $file['id'], 'name' => 'renamed.txt', 'revision' => 2]);

        $fixture->call('mountAction', 'move')->call('forceRender')
            ->set('mountedActions.0.data.parent', $folder->public_id)->call('callMountedAction')->assertHasNoErrors();
        $this->assertDatabaseHas('fms_entries', ['public_id' => $file['id'], 'parent_id' => $folder->id, 'revision' => 3]);
        $fixture->call('mountAction', 'trash')->call('forceRender')->call('callMountedAction')->assertHasNoErrors();
        $this->assertNotNull(DB::table('fms_entries')->where('public_id', $file['id'])->value('trashed_at'));
        $fixture->call('mountAction', 'restore')->call('forceRender')->call('callMountedAction')->assertHasNoErrors();
        $this->assertDatabaseHas('fms_entries', ['public_id' => $file['id'], 'trashed_at' => null, 'revision' => 5]);
        $fixture->call('mountAction', 'download')->assertRedirect(
            url($this->url($workspace, 'entries/'.$file['id'].'/versions/'.$file['versionId'].'/content')).'?download=1'
        );
    }

    public function test_native_confirmation_keeps_the_mount_revision_and_rechecks_access(): void
    {
        $workspace = $this->workspace();
        $file = $this->file($workspace);
        $fixture = Livewire::test(StudioActionFixture::class, ['workspace' => $workspace, 'entryId' => $file['id']])
            ->call('mountAction', 'rename')->call('forceRender')
            ->set('mountedActions.0.data.name', 'stale.txt');
        DB::table('fms_entries')->where('public_id', $file['id'])->update(['revision' => 2, 'name' => 'current.txt']);
        $fixture->call('callMountedAction')->assertStatus(409);
        $this->assertDatabaseHas('fms_entries', ['public_id' => $file['id'], 'name' => 'current.txt', 'revision' => 2]);

        $fixture = Livewire::test(StudioActionFixture::class, ['workspace' => $workspace, 'entryId' => $file['id']])
            ->call('mountAction', 'trash')->call('forceRender');
        DB::table('fms_memberships')->delete();
        $fixture->call('callMountedAction')->assertStatus(404);
        $this->assertDatabaseHas('fms_entries', ['public_id' => $file['id'], 'trashed_at' => null]);
    }

    public function test_native_picker_resolves_the_selected_version_before_dispatch(): void
    {
        $workspace = $this->workspace();
        $file = $this->file($workspace);
        Livewire::test(StudioActionFixture::class, ['workspace' => $workspace, 'entryId' => $file['id']])
            ->call('mountAction', 'picker')->call('forceRender')->assertSeeHtml('mountMediaStudio')
            ->set('mountedActions.0.data.reference', ['assetId' => $file['id'], 'versionId' => $file['versionId']])
            ->call('callMountedAction')->assertHasNoErrors()
            ->assertDispatched('media-studio-file-selected', fn (string $event, array $data): bool => $data['reference']['assetId'] === $file['id']);

        $foreign = $this->file($this->workspace('Other'));
        Livewire::test(StudioActionFixture::class, ['workspace' => $workspace, 'entryId' => $file['id']])
            ->call('mountAction', 'picker')->call('forceRender')
            ->set('mountedActions.0.data.reference', ['assetId' => $foreign['id'], 'versionId' => $foreign['versionId']])
            ->call('callMountedAction')->assertStatus(404);
    }

    public function test_native_table_bulk_action_moves_selected_records_atomically_and_rejects_changed_selection(): void
    {
        $workspace = $this->workspace();
        $first = $this->file($workspace);
        $this->patchJson($this->url($workspace, 'entries/'.$first['id']), ['name' => 'first.txt', 'revision' => 1])->assertOk();
        $second = $this->file($workspace);
        $folder = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Destination'])->assertCreated()->json('id');
        $records = StudioEntryFixture::query()->whereIn('public_id', [$first['id'], $second['id']])->get();
        $table = Livewire::test(StudioTableFixture::class, ['workspace' => $workspace]);
        $table->mountTableBulkAction('move', $records)->call('forceRender')
            ->set('mountedActions.0.data.parent', $folder)
            ->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame(2, DB::table('fms_entries')->whereIn('public_id', [$first['id'], $second['id']])->whereNotNull('parent_id')->count());
        $table->assertDispatched('deselectAllTableRecords');
        $table->mountTableBulkAction('trash', $records)->call('forceRender')->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame(2, DB::table('fms_entries')->whereNotNull('trashed_at')->count());
        $table->mountTableBulkAction('restore', $records)->call('forceRender')->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame(0, DB::table('fms_entries')->whereNotNull('trashed_at')->count());

        $table->mountTableBulkAction('trash', $records)->call('forceRender');
        $table->set('selectedTableRecords', [$records[0]->getKey()])->call('callMountedAction')->assertStatus(409);
        $this->assertSame(0, DB::table('fms_entries')->whereNotNull('trashed_at')->count());
    }

    public function test_native_bulk_actions_reject_select_all_and_enforce_individual_authorization(): void
    {
        $workspace = $this->workspace();
        $file = $this->file($workspace);
        $record = StudioEntryFixture::query()->where('public_id', $file['id'])->first();
        $table = Livewire::test(StudioTableFixture::class, ['workspace' => $workspace]);
        $table->set('isTrackingDeselectedTableRecords', true)
            ->call('mountAction', 'trash', [], ['table' => true, 'bulk' => true])->assertStatus(422);
        $this->assertDatabaseHas('fms_entries', ['public_id' => $file['id'], 'trashed_at' => null]);

        $table = Livewire::test(StudioTableFixture::class, ['workspace' => $workspace]);
        $table->mountTableBulkAction('denied', [$record])->assertStatus(403);
        $this->assertDatabaseHas('fms_entries', ['public_id' => $file['id'], 'trashed_at' => null]);
    }

    public function test_confirmation_snapshot_supports_maximum_batch_and_rejects_tampering_expiry_and_changed_actor(): void
    {
        $principal = new Principal('user', 'member-uuid-001');
        $workspace = $this->workspace();
        $entries = [];
        for ($i = 0; $i < 100; $i++) {
            $entries[] = ['id' => (string) Str::ulid(), 'revision' => 100];
        }
        $snapshot = ActionSnapshot::issue($principal, $workspace, 'batch', $entries);
        $this->assertLessThanOrEqual(16384, strlen($snapshot));
        $this->assertSame($entries, ActionSnapshot::verify($snapshot, $principal, $workspace, 'batch', array_reverse($entries)));

        foreach ([
            fn () => ActionSnapshot::verify($snapshot.'broken', $principal, $workspace, 'batch', $entries),
            fn () => ActionSnapshot::verify($snapshot, new Principal('employee', $principal->id), $workspace, 'batch', $entries),
            fn () => ActionSnapshot::verify($snapshot, $principal, $workspace, 'different', $entries),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A mismatched confirmation must be rejected.');
            } catch (HttpException $error) {
                $this->assertContains($error->getStatusCode(), [409, 422]);
            }
        }
        $this->travel(31)->minutes();
        try {
            ActionSnapshot::verify($snapshot, $principal, $workspace, 'batch', $entries);
            $this->fail('An expired confirmation must be rejected.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    public function test_actions_use_the_current_panel_guard_without_falling_back_to_another_signed_in_user(): void
    {
        $workspace = $this->workspace();
        $file = $this->file($workspace);
        config(['auth.guards.studio-admin' => ['driver' => 'session', 'provider' => 'users']]);
        Auth::guard('studio-admin')->setUser(new GenericUser(['id' => 'panel-member-without-access']));
        Filament::setCurrentPanel(Panel::make()->id('actions')->authGuard('studio-admin'));
        Livewire::test(StudioActionFixture::class, ['workspace' => $workspace, 'entryId' => $file['id']])
            ->call('mountAction', 'rename')->assertStatus(404);
        $this->assertDatabaseHas('fms_entries', ['public_id' => $file['id'], 'revision' => 1]);
    }
}

final class StudioActionFixture extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    #[Locked]
    public string $workspace;

    #[Locked]
    public string $entryId;

    public function createAction(): CreateFolderAction
    {
        return CreateFolderAction::make('create')->workspace($this->workspace);
    }

    public function renameAction(): RenameAction
    {
        return RenameAction::make('rename')->workspace($this->workspace)->record(fn (): array => $this->entry());
    }

    public function moveAction(): MoveAction
    {
        return MoveAction::make('move')->workspace($this->workspace)->record(fn (): array => $this->entry());
    }

    public function trashAction(): TrashAction
    {
        return TrashAction::make('trash')->workspace($this->workspace)->record(fn (): array => $this->entry());
    }

    public function restoreAction(): RestoreAction
    {
        return RestoreAction::make('restore')->workspace($this->workspace)->record(fn (): array => $this->entry());
    }

    public function downloadAction(): DownloadAction
    {
        return DownloadAction::make('download')->workspace($this->workspace)->record(fn (): array => $this->entry());
    }

    public function pickerAction(): SelectFileAction
    {
        return SelectFileAction::make('picker')->workspace($this->workspace)->assets('/studio.js');
    }

    private function entry(): array
    {
        return ['__key' => $this->entryId, ...(array) DB::table('fms_entries')->where('public_id', $this->entryId)->first()];
    }

    public function render(): string
    {
        return '<div>{{ $this->createAction }}{{ $this->renameAction }}{{ $this->moveAction }}{{ $this->trashAction }}{{ $this->restoreAction }}{{ $this->downloadAction }}{{ $this->pickerAction }}<x-filament-actions::modals /></div>';
    }
}

final class StudioEntryFixture extends Model
{
    protected $table = 'fms_entries';

    protected $guarded = [];

    protected $casts = ['revision' => 'integer'];
}

final class StudioTableFixture extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public string $workspace;

    public function table(Table $table): Table
    {
        $workspace = DB::table('fms_workspaces')->where('public_id', $this->workspace)->value('id');

        return $table->query(StudioEntryFixture::query()->where('workspace_id', $workspace))
            ->columns([TextColumn::make('name')])
            ->toolbarActions([
                MoveBulkAction::make('move')->workspace($this->workspace),
                TrashBulkAction::make('trash')->workspace($this->workspace),
                RestoreBulkAction::make('restore')->workspace($this->workspace),
                TrashBulkAction::make('denied')->workspace($this->workspace)->authorizeIndividualRecords(fn (): bool => false),
            ]);
    }

    public function render(): string
    {
        return '<div>{{ $this->table }}<x-filament-actions::modals /></div>';
    }
}
