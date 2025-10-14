<?php
use Statamic\Facades\Entry;

public function showSlider()
{
    $entries = Entry::query()
        ->where('collection', 'your_collection_name')
        ->where('status', 'published')
        ->get();

    return view('slider', compact('entries'));
}