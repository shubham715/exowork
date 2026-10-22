<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class HeroSlideController extends Controller
{
    public function index()
    {
        return response()->json(['slides' => DB::table('hero_slides')->orderBy('sort_order')->orderBy('id')->get()->map(function ($slide) {
            $slide->image_url = str_starts_with($slide->image_path, '/') ? $slide->image_path : route('hero-slide.image', ['id' => $slide->id], false).'?v='.substr(sha1($slide->image_path), 0, 12);
            return $slide;
        })]);
    }

    public function image(int $id)
    {
        $slide = DB::table('hero_slides')->find($id);
        abort_unless($slide && str_starts_with($slide->image_path, 'hero-slides/') && Storage::disk('local')->exists($slide->image_path), 404);
        return response()->file(Storage::disk('local')->path($slide->image_path));
    }

    public function save(Request $request, ?int $id = null)
    {
        abort_unless($request->user()->hasPermission('masters.manage'), 403);
        $existing = $id ? DB::table('hero_slides')->find($id) : null;
        if ($id) abort_unless($existing, 404);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['required', 'string', 'max:5000'],
            'sort_order' => ['required', 'integer', 'between:0,65535'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);
        // Rebuild allowed tags without attributes, so formatting cannot introduce scripts.
        $subtitle = preg_replace_callback('/<\/?([a-z0-9]+)\b[^>]*>/i', function ($match) {
            $tag = strtolower($match[1]);
            return in_array($tag, ['b', 'strong', 'i', 'em', 'br'], true) ? '<'.(str_starts_with($match[0], '</') ? '/' : '').$tag.'>' : '';
        }, strip_tags($data['subtitle'], '<b><strong><i><em><br>'));
        $path = $request->hasFile('image') ? $request->file('image')->store('hero-slides', 'local') : ($existing->image_path ?? '/hero1.png');
        $row = ['title' => trim($data['title']), 'subtitle' => $subtitle, 'sort_order' => $data['sort_order'], 'image_path' => $path, 'updated_at' => now()];
        if ($id) DB::table('hero_slides')->where('id', $id)->update($row);
        else $id = DB::table('hero_slides')->insertGetId($row + ['created_at' => now()]);
        if ($existing && $existing->image_path !== $path && str_starts_with($existing->image_path, 'hero-slides/')) Storage::disk('local')->delete($existing->image_path);
        return response()->json(['id' => $id], $existing ? 200 : 201);
    }

    public function destroy(Request $request, int $id)
    {
        abort_unless($request->user()->hasPermission('masters.manage'), 403);
        $slide = DB::table('hero_slides')->find($id);
        abort_unless($slide, 404);
        DB::table('hero_slides')->delete($id);
        if (str_starts_with($slide->image_path, 'hero-slides/')) Storage::disk('local')->delete($slide->image_path);
        return response()->json(['message' => 'Slide deleted.']);
    }
}
