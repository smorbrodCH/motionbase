<?php

namespace App\Http\Controllers;

use App\Models\Topic;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Support\Facades\View;

abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * What an embedded page shows instead of its content while the course
     * is not readable: nothing for a draft (404), a note for a course being
     * revised. Null when the content can be shown.
     */
    protected function courseLock(?Topic $topic): ?ViewContract
    {
        abort_if(! $topic || $topic->isDraft(), 404);

        return $topic->isInRevision() ? View::make('embed.revision', ['topic' => $topic]) : null;
    }
}
