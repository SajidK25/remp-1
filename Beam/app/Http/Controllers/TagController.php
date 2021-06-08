<?php

namespace App\Http\Controllers;

use App\Article;
use App\ArticlesDataTable;
use App\Author;
use Illuminate\Http\Request;
use App\Http\Resources\TagResource;
use App\Model\Tag;
use App\Section;
use App\TagsDataTable;
use Yajra\DataTables\DataTables;
use Html;

class TagController extends Controller
{
    public function index(Request $request)
    {
        return response()->format([
            'html' => view('tags.index', [
                'tags' => Tag::all()->pluck('name', 'id'),
                'contentTypes' => array_merge(
                    ['all'],
                    Article::groupBy('content_type')->pluck('content_type')->toArray()
                ),
                'publishedFrom' => $request->input('published_from', 'today - 30 days'),
                'publishedTo' => $request->input('published_to', 'now'),
                'conversionFrom' => $request->input('conversion_from', 'today - 30 days'),
                'conversionTo' => $request->input('conversion_to', 'now'),
                'contentType' => $request->input('content_type', 'all'),
            ]),
            'json' => TagResource::collection(Tag::paginate()),
        ]);
    }

    public function show(Tag $tag, Request $request)
    {
        return response()->format([
            'html' => view('tags.show', [
                'tag' => $tag,
                'tags' => Tag::all()->pluck('name', 'id'),
                'contentTypes' => Article::groupBy('content_type')->pluck('content_type', 'content_type'),
                'sections' => Section::all()->pluck('name', 'id'),
                'authors' => Author::all()->pluck('name', 'id'),
                'publishedFrom' => $request->input('published_from', 'today - 30 days'),
                'publishedTo' => $request->input('published_to', 'now'),
                'conversionFrom' => $request->input('conversion_from', 'today - 30 days'),
                'conversionTo' => $request->input('conversion_to', 'now'),
            ]),
            'json' => new TagResource($tag),
        ]);
    }

    public function dtTags(Request $request, DataTables $datatables, TagsDataTable $tagsDataTable)
    {
        return $tagsDataTable->getDataTable($request, $datatables);
    }

    public function dtArticles(Tag $tag, Request $request, Datatables $datatables, ArticlesDataTable $articlesDataTable)
    {
        $articlesDataTable->setTag($tag);
        return $articlesDataTable->getDataTable($request, $datatables);
    }
}
