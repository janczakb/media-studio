<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use UseFlex\MediaStudio\Http\ArchiveController;
use UseFlex\MediaStudio\Http\CommentsController;
use UseFlex\MediaStudio\Http\NotesController;
use UseFlex\MediaStudio\Http\StudioController;
use UseFlex\MediaStudio\Search\SearchController;

// Explicit surface: account administration, source imports and paid AI jobs are not exposed.
Route::get('workspaces/{workspace}', [StudioController::class, 'workspace']);
Route::get('workspaces/{workspace}/entries', [StudioController::class, 'browse']);
Route::get('workspaces/{workspace}/entries/{entry}', [StudioController::class, 'details']);
Route::get('workspaces/{workspace}/entries/{entry}/content', [StudioController::class, 'content']);
Route::get('workspaces/{workspace}/entries/{entry}/preview', [StudioController::class, 'preview']);
Route::get('workspaces/{workspace}/entries/{entry}/thumbnail', [StudioController::class, 'thumbnail']);
Route::get('workspaces/{workspace}/entries/{entry}/versions/{version}/content', [StudioController::class, 'content']);
Route::get('workspaces/{workspace}/entries/{entry}/versions/{version}/renditions/{preset}', [StudioController::class, 'rendition'])->where('preset', '[a-zA-Z][a-zA-Z0-9_-]{0,63}');
Route::get('workspaces/{workspace}/tags', [StudioController::class, 'tagDirectory']);
Route::get('workspaces/{workspace}/search', [SearchController::class, 'index']);
Route::post('workspaces/{workspace}/folders', [StudioController::class, 'createFolder']);
Route::post('workspaces/{workspace}/folders/resolve', [StudioController::class, 'resolveFolders']);
Route::post('workspaces/{workspace}/uploads', [StudioController::class, 'upload']);
Route::patch('workspaces/{workspace}/entries/{entry}', [StudioController::class, 'update']);
Route::patch('workspaces/{workspace}/entries/{entry}/tags', [StudioController::class, 'replaceTags']);
Route::post('workspaces/{workspace}/entries/{entry}/trash', [StudioController::class, 'trash']);
Route::post('workspaces/{workspace}/entries/{entry}/restore', [StudioController::class, 'restore']);
Route::post('workspaces/{workspace}/entries/bulk', [StudioController::class, 'bulk']);
Route::get('workspaces/{workspace}/download', [ArchiveController::class, 'download']);
Route::post('workspaces/{workspace}/archive-jobs', [ArchiveController::class, 'create']);
Route::get('workspaces/{workspace}/archive-jobs/{job}', [ArchiveController::class, 'status']);
Route::get('workspaces/{workspace}/archive-jobs/{job}/result', [ArchiveController::class, 'result']);
Route::get('workspaces/{workspace}/notes/{entry}', [NotesController::class, 'show']);
Route::post('workspaces/{workspace}/notes', [NotesController::class, 'store']);
Route::patch('workspaces/{workspace}/notes/{entry}', [NotesController::class, 'update']);
Route::get('workspaces/{workspace}/entries/{entry}/comments', [CommentsController::class, 'index']);
Route::post('workspaces/{workspace}/entries/{entry}/comments', [CommentsController::class, 'store']);
Route::patch('workspaces/{workspace}/entries/{entry}/comments/{comment}', [CommentsController::class, 'update']);
require __DIR__.'/uploads.php';

Route::get('workspaces/{workspace}/folders/{entry}/context', [StudioController::class, 'folderContext']);
Route::get('workspaces/{workspace}/folders/{entry}/ancestors', [StudioController::class, 'folderAncestors']);
Route::get('workspaces/{workspace}/navigation/changes', [StudioController::class, 'navigationChanges']);
Route::post('workspaces/{workspace}/navigation/operations/{operation}/undo', [StudioController::class, 'undoNavigation']);

Route::get('workspaces/{workspace}/tree-operations/{operation}', [StudioController::class, 'treeOperation']);
Route::post('workspaces/{workspace}/tree-operations/{operation}/advance', [StudioController::class, 'advanceTreeOperation']);
Route::post('workspaces/{workspace}/tree-operations/{operation}/cancel', [StudioController::class, 'cancelTreeOperation']);
