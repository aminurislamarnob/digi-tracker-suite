<?php

use Illuminate\Support\Facades\Route;

/*
 * There is no marketing site here, and the ingest endpoints deliberately
 * live at bare paths, so the root is just a signpost to the panel.
 */
Route::redirect('/', '/admin');
