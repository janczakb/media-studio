<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class NotesTest extends TestCase
{
    private function draft(): array
    {
        $text = ['type' => 'text', 'text' => 'Draft content', 'marks' => [['type' => 'bold']]];
        $paragraph = ['type' => 'paragraph', 'content' => [$text]];

        return ['title' => 'Launch brief', 'background' => '#fef3c7', 'key' => 'note-request-key-0001', 'document' => ['type' => 'doc', 'content' => [$paragraph]]];
    }

    public function test_note_is_saved_only_on_submit_and_can_be_reopened_and_updated(): void
    {
        $w = $this->workspace();
        self::assertSame(0, DB::table('fms_notes')->count());
        $draft = $this->draft();
        $note = $this->postJson($this->url($w, 'notes'), $draft)->assertCreated()->assertJsonPath('entry.mediaType', 'note')->assertJsonPath('entry.noteExcerpt', 'Draft content')->assertJsonPath('entry.noteBackground', '#fef3c7')->json();
        $this->postJson($this->url($w, 'notes'), $draft)->assertCreated()->assertJsonPath('entry.id', $note['entry']['id']);
        self::assertSame(1, DB::table('fms_notes')->count());
        $this->getJson($this->url($w, 'entries'))->assertOk()->assertJsonFragment(['noteExcerpt' => 'Draft content']);
        $path = $this->url($w, 'notes/'.$note['entry']['id']);
        $this->getJson($path)->assertOk()->assertJsonPath('document.content.0.content.0.text', 'Draft content');
        $this->patchJson($path, [...$draft, 'title' => 'Updated brief', 'revision' => $note['entry']['revision']])->assertOk()->assertJsonPath('entry.name', 'Updated brief');
        $this->patchJson($path, [...$draft, 'revision' => $note['entry']['revision']])->assertConflict();
        $other = $this->workspace('Other');
        $this->getJson($this->url($other, 'notes/'.$note['entry']['id']))->assertNotFound();
    }

    public function test_notes_reject_unsafe_links_unsupported_nodes_and_missing_permission(): void
    {
        $w = $this->workspace();
        $draft = $this->draft();
        $draft['document']['content'][0]['content'][0]['marks'] = [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]];
        $this->postJson($this->url($w, 'notes'), $draft)->assertUnprocessable();
        $draft = $this->draft();
        $draft['document']['content'][] = ['type' => 'script'];
        $this->postJson($this->url($w, 'notes'), $draft)->assertUnprocessable();
        Gate::define('studio.notes.create', fn () => false);
        $this->postJson($this->url($w, 'notes'), $this->draft())->assertForbidden();
        self::assertSame(0, DB::table('fms_notes')->count());
    }

    public function test_save_strips_pasted_attributes_and_unsupported_formatting(): void
    {
        $w = $this->workspace();
        $draft = $this->draft();
        $draft['document']['attrs'] = ['id' => 'foreign', 'style' => 'color:red'];
        $draft['document']['content'][0]['attrs'] = ['textAlign' => 'center', 'class' => 'pasted', 'id' => 'external'];
        $draft['document']['content'][0]['content'][0]['marks'] = [
            ['type' => 'bold', 'attrs' => ['style' => 'font-size:99px']],
            ['type' => 'textStyle', 'attrs' => ['fontFamily' => 'Unknown']],
        ];
        $draft['document']['content'][] = ['type' => 'image', 'attrs' => ['src' => 'https://example.com/image.png']];
        $response = $this->postJson($this->url($w, 'notes'), $draft)->assertCreated();
        $doc = $response->json('document');
        self::assertArrayNotHasKey('attrs', $doc);
        self::assertCount(1, $doc['content']);
        self::assertSame(['textAlign' => 'center'], $doc['content'][0]['attrs']);
        self::assertSame([['type' => 'bold']], $doc['content'][0]['content'][0]['marks']);
        $stored = json_decode(DB::table('fms_notes')->value('document'), true);
        self::assertSame($doc, $stored);
        self::assertSame('Draft content', $response->json('entry.noteExcerpt'));
    }
}
