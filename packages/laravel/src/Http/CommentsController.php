<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use UseFlex\MediaStudio\Comments\FileComments;

final class CommentsController
{
    public function __construct(private FileComments $comments) {}

    public function index(Request $r, string $workspace, string $entry): JsonResponse
    {
        $d = $r->validate(['filter' => 'nullable|in:all,open,resolved', 'anchorVersion' => 'nullable|ulid', 'cursor' => 'nullable|string|max:4096']);

        return response()->json($this->comments->index($r->user(), $workspace, $entry, $d));
    }

    public function store(Request $r, string $workspace, string $entry): JsonResponse
    {
        $d = $r->validate(['body' => 'required|string|max:5000', 'anchor' => 'sometimes|array:versionId,x,y', 'anchor.versionId' => 'required_with:anchor|ulid', 'anchor.x' => 'required_with:anchor|integer|min:0|max:1000', 'anchor.y' => 'required_with:anchor|integer|min:0|max:1000', 'document' => 'sometimes|string|json|max:50000', 'mentions' => 'sometimes|array|max:10', 'mentions.*' => ['string', 'regex:/\A[0-9]{1,20}\z/', 'distinct'], 'key' => 'required|string|min:16|max:100']);

        return response()->json($this->comments->store($r->user(), $workspace, $entry, $d), 201);
    }

    public function update(Request $r, string $workspace, string $entry, string $comment): JsonResponse
    {
        $d = $r->validate(['revision' => 'required|integer|min:1', 'body' => 'required_with:mentions|sometimes|required|string|max:5000', 'document' => 'sometimes|string|json|max:50000', 'mentions' => 'sometimes|array|max:10', 'mentions.*' => ['string', 'regex:/\A[0-9]{1,20}\z/', 'distinct'], 'resolved' => 'sometimes|boolean', 'delete' => 'sometimes|boolean']);

        return response()->json($this->comments->update($r->user(), $workspace, $entry, $comment, $d));
    }

    public function mentions(Request $r, string $workspace, string $entry): JsonResponse
    {
        $d = $r->validate(['search' => 'nullable|string|max:100']);

        return response()->json($this->comments->mentions($r->user(), $workspace, $entry, $d));
    }
}
