<?php

namespace App\Http\Controllers;

use App\Models\KbArticle;
use App\Models\KbCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Str;

class KbArticleController extends Controller
{
    /**
     * Display a listing of articles for management (Admin).
     */
    public function index(Request $request)
    {
        $articles = KbArticle::with(['category', 'author'])
            ->when($request->search, function ($query, $search) {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhereHas('category', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%");
                    });
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('KbArticles/Index', [
            'articles' => $articles,
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Show the form for creating a new article.
     */
    public function create()
    {
        return Inertia::render('KbArticles/Form', [
            'isEditing' => false,
            'categories' => KbCategory::all(['id', 'name']),
        ]);
    }

    /**
     * Store a newly created article.
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'category_name' => 'required|string|max:255',
            'is_published' => 'boolean',
        ]);

        $category = KbCategory::firstOrCreate(
            ['name' => $request->category_name],
            ['slug' => Str::slug($request->category_name)]
        );

        KbArticle::create([
            'title' => $request->title,
            'content' => $request->content,
            'kb_category_id' => $category->id,
            'author_id' => auth()->id(),
            'is_published' => $request->is_published ?? false,
        ]);

        return redirect()->route('kb-articles.index')
            ->with('success', 'Article created successfully.');
    }

    /**
     * Show the form for editing the article.
     */
    public function edit(KbArticle $kbArticle)
    {
        return Inertia::render('KbArticles/Form', [
            'isEditing' => true,
            'article' => $kbArticle->load('category'),
            'categories' => KbCategory::all(['id', 'name']),
        ]);
    }

    /**
     * Update the article.
     */
    public function update(Request $request, KbArticle $kbArticle)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'category_name' => 'required|string|max:255',
            'is_published' => 'boolean',
        ]);

        $category = KbCategory::firstOrCreate(
            ['name' => $request->category_name],
            ['slug' => Str::slug($request->category_name)]
        );

        $kbArticle->update([
            'title' => $request->title,
            'content' => $request->content,
            'kb_category_id' => $category->id,
            'is_published' => $request->is_published,
        ]);

        return redirect()->route('kb-articles.index')
            ->with('success', 'Article updated successfully.');
    }

    /**
     * Remove the article.
     */
    public function destroy(KbArticle $kbArticle)
    {
        $kbArticle->delete();

        return redirect()->back()
            ->with('success', 'Article deleted successfully.');
    }

    /**
     * Knowledge Base Public Portal.
     */
    public function portal(Request $request)
    {
        $articles = KbArticle::with(['category', 'author'])
            ->where('is_published', true)
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('content', 'like', "%{$search}%")
                        ->orWhereHas('category', function ($cq) use ($search) {
                            $cq->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($request->category, function ($query, $category) {
                $query->whereHas('category', function ($q) use ($category) {
                    $q->where('slug', $category);
                });
            })
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $categories = KbCategory::whereHas('articles', function ($q) {
            $q->where('is_published', true);
        })->withCount(['articles' => function ($q) {
            $q->where('is_published', true);
        }])->get();

        return Inertia::render('KnowledgeBase/Index', [
            'articles' => $articles,
            'categories' => $categories,
            'filters' => $request->only(['search', 'category']),
        ]);
    }

    /**
     * Display the specified article (Public).
     */
    public function show(KbArticle $kbArticle)
    {
        if (!$kbArticle->is_published && !auth()->user()->can('kb_articles.view')) {
            abort(404);
        }

        // Track unique view for logged in user
        $userId = auth()->id();
        $hasViewed = \Illuminate\Support\Facades\DB::table('kb_article_views')
            ->where('kb_article_id', $kbArticle->id)
            ->where('user_id', $userId)
            ->exists();

        if (!$hasViewed) {
            \Illuminate\Support\Facades\DB::table('kb_article_views')->insert([
                'kb_article_id' => $kbArticle->id,
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $kbArticle->increment('views');
        }

        return Inertia::render('KnowledgeBase/Show', [
            'article' => $kbArticle->load(['category', 'author']),
            'relatedArticles' => KbArticle::where('kb_category_id', $kbArticle->kb_category_id)
                ->where('id', '!=', $kbArticle->id)
                ->where('is_published', true)
                ->limit(5)
                ->get()
        ]);
    }

    /**
     * Handle feedback (helpful/not helpful).
     */
    public function submitFeedback(Request $request, KbArticle $kbArticle)
    {
        $request->validate([
            'was_helpful' => 'required|boolean'
        ]);

        if ($request->was_helpful) {
            $kbArticle->increment('helpful_yes');
        } else {
            $kbArticle->increment('helpful_no');
        }

        return redirect()->back()->with('success', 'Thank you for your feedback!');
    }

    /**
     * Remove the specified category.
     */
    public function destroyCategory(KbCategory $kbCategory)
    {
        if ($kbCategory->articles()->count() > 0) {
            return redirect()->back()->with('error', 'Cannot delete category that has articles assigned to it. Please move or delete the articles first.');
        }

        $kbCategory->delete();

        return redirect()->back()->with('success', 'Category deleted successfully.');
    }

    /**
     * Get categories for autocomplete.
     */
    public function getCategories(Request $request)
    {
        return KbCategory::where('name', 'like', "%{$request->q}%")
            ->limit(10)
            ->get(['id', 'name']);
    }
}
