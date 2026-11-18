<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Notes;

/** Validate structured content before it enters either the database or an editor. Never accept HTML. */
final class NoteDocument
{
    private const NODES = ['doc', 'paragraph', 'text', 'heading', 'blockquote', 'bulletList', 'orderedList', 'listItem', 'taskList', 'taskItem', 'codeBlock', 'horizontalRule', 'hardBreak', 'image'];

    private const MARKS = ['bold', 'italic', 'strike', 'code', 'underline', 'highlight', 'link', 'subscript', 'superscript'];

    public function encode(array $document): string
    {
        abort_unless(($document['type'] ?? null) === 'doc', 422, 'Invalid note document.');
        $count = 0;
        $document = $this->clean($document, 0, $count);
        $count = 0;
        $this->node($document, 0, $count);
        $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        abort_if(strlen($json) > 2097152, 422, 'The note is too large.');

        return $json;
    }

    public function excerpt(array $document): string
    {
        $parts = [];
        $walk = function (array $node) use (&$walk, &$parts): void {
            if (isset($node['text'])) {
                $parts[] = $node['text'];
            }
            foreach ($node['content'] ?? [] as $child) {
                $walk($child);
            }
            if (in_array($node['type'] ?? '', ['paragraph', 'heading', 'listItem'], true)) {
                $parts[] = "\n";
            }
        };
        $walk($document);

        return mb_substr(trim(preg_replace('/[ \t]+/u', ' ', implode('', $parts))), 0, 600);
    }

    /** Canonical toolbar-only schema: never persist pasted HTML attributes. */
    private function clean(array $node, int $depth, int &$count): array
    {
        abort_if($depth > 24 || ++$count > 5000, 422, 'The note is too complex.');
        $type = $node['type'] ?? null;
        abort_unless(in_array($type, self::NODES, true), 422, 'Unsupported note node.');
        $result = ['type' => $type];
        $keys = match ($type) {
            'paragraph' => ['textAlign'],
            'heading' => ['level', 'textAlign'],
            'orderedList' => ['start'],
            'taskItem' => ['checked'],
            default => [],
        };
        if (isset($node['attrs'])) {
            abort_unless(is_array($node['attrs']), 422);
            $attrs = array_intersect_key($node['attrs'], array_flip($keys));
            if (isset($attrs['level'])) {
                abort_unless(is_int($attrs['level']) && $attrs['level'] >= 1 && $attrs['level'] <= 4, 422);
            }
            if (isset($attrs['start'])) {
                abort_unless(is_int($attrs['start']) && $attrs['start'] >= 1 && $attrs['start'] <= 1000000, 422);
            }
            if (isset($attrs['checked'])) {
                abort_unless(is_bool($attrs['checked']), 422);
            }
            if ($attrs !== []) {
                $result['attrs'] = $attrs;
            }
        }
        if ($type === 'text') {
            abort_unless(isset($node['text']) && is_string($node['text']) && mb_strlen($node['text']) <= 100000, 422);
            $result['text'] = $node['text'];
            abort_unless(! isset($node['marks']) || (is_array($node['marks']) && array_is_list($node['marks']) && count($node['marks']) <= 12), 422);
            foreach ($node['marks'] ?? [] as $mark) {
                abort_unless(is_array($mark), 422);
                if (! in_array($mark['type'] ?? null, self::MARKS, true)) {
                    continue;
                }
                $clean = ['type' => $mark['type']];
                $allowed = match ($mark['type']) {
                    'link' => ['href'], 'highlight' => ['color'], default => []
                };
                if (isset($mark['attrs'])) {
                    abort_unless(is_array($mark['attrs']), 422);
                    $attrs = array_intersect_key($mark['attrs'], array_flip($allowed));
                    $this->attributes($attrs);
                    if ($mark['type'] === 'link') {
                        abort_unless(isset($attrs['href']), 422);
                        $attrs += ['target' => '_blank', 'rel' => 'noopener noreferrer nofollow'];
                    }
                    if ($attrs !== []) {
                        $clean['attrs'] = $attrs;
                    }
                }
                $result['marks'][] = $clean;
            }
        }
        if (isset($node['content'])) {
            abort_unless(is_array($node['content']) && array_is_list($node['content']), 422);
            $result['content'] = [];
            foreach ($node['content'] as $child) {
                abort_unless(is_array($child), 422);
                // Image insertion and horizontal rules are not exposed by the toolbar.
                if (in_array($child['type'] ?? null, ['image', 'horizontalRule'], true)) {
                    continue;
                }
                $result['content'][] = $this->clean($child, $depth + 1, $count);
            }
        }

        return $result;
    }

    private function node(array $node, int $depth, int &$count): void
    {
        abort_if($depth > 24 || ++$count > 5000, 422, 'The note is too complex.');
        abort_unless(in_array($node['type'] ?? null, self::NODES, true), 422, 'Unsupported note node.');
        abort_if(array_diff(array_keys($node), ['type', 'attrs', 'content', 'text', 'marks']) !== [], 422);
        if (isset($node['text'])) {
            abort_unless(is_string($node['text']) && mb_strlen($node['text']) <= 100000, 422);
        }
        if (isset($node['attrs'])) {
            $this->attributes($node['attrs']);
        }
        if (isset($node['marks'])) {
            abort_unless(is_array($node['marks']) && array_is_list($node['marks']) && count($node['marks']) <= 12, 422);
            foreach ($node['marks'] as $mark) {
                abort_unless(is_array($mark) && in_array($mark['type'] ?? null, self::MARKS, true) && array_diff(array_keys($mark), ['type', 'attrs']) === [], 422);
                if (isset($mark['attrs'])) {
                    $this->attributes($mark['attrs']);
                }
            }
        }
        if (isset($node['content'])) {
            abort_unless(is_array($node['content']) && array_is_list($node['content']), 422);
            foreach ($node['content'] as $child) {
                abort_unless(is_array($child), 422);
                $this->node($child, $depth + 1, $count);
            }
        }
    }

    private function attributes(mixed $attributes): void
    {
        abort_unless(is_array($attributes), 422);
        $allowed = ['level', 'textAlign', 'start', 'type', 'checked', 'language', 'color', 'href', 'target', 'rel', 'class', 'src', 'alt', 'title', 'width', 'height', 'id'];
        foreach ($attributes as $key => $value) {
            abort_unless(in_array($key, $allowed, true) && (is_scalar($value) || $value === null), 422, 'Unsupported note attribute.');
            if (is_string($value)) {
                abort_if(strlen($value) > 2000000, 422);
            }
            if ($key === 'href' && $value !== null) {
                abort_unless(is_string($value) && preg_match('~\A(?:https?://|mailto:|tel:|/[^/]|\#)~i', $value), 422, 'Unsafe link.');
            }
            if ($key === 'src' && $value !== null) {
                abort_unless(is_string($value) && preg_match('~\A(?:https?://|/[^/]|data:image/(?:png|jpeg|webp|avif);base64,)~i', $value), 422, 'Unsafe image.');
            }
            if ($key === 'color' && $value !== null) {
                abort_unless(is_string($value) && preg_match('/\A#[0-9a-f]{3,8}\z/i', $value), 422);
            }
            if ($key === 'textAlign' && $value !== null) {
                abort_unless(in_array($value, ['left', 'center', 'right', 'justify'], true), 422);
            }
            if ($key === 'level') {
                abort_unless(is_int($value) && $value >= 1 && $value <= 6, 422);
            }
            if ($key === 'class') {
                abort_unless($value === null || $value === '', 422);
            }
        }
    }
}
