<?php

/**
 * v2.84.0 -- THE SERVER-SIDE HALF OF "EVERY WORKSPACE HAS AN OVERVIEW".
 *
 * `resources/js/lib/workspaces.js` remains the single source of truth for
 * NAVIGATION -- what a workspace contains, in what order, behind which
 * gates. This file is deliberately much smaller and answers one question
 * the server has to answer on its own:
 *
 *   "This person has no Global Company Dashboard. Where do they land?"
 *
 * That question is asked by the sign-in redirect and by `/dashboard` itself
 * for a Starter or Professional customer, both of which run long before any
 * JavaScript exists. Reading the JSX from PHP is not an option, and
 * hardcoding `hse.dashboard` in two controllers is how the two halves drift.
 *
 * It is NOT a second navigation registry: it holds one route per workspace
 * and nothing else -- no items, no icons, no gates. A test asserts every key
 * here is a workspace IOMS actually sells and that every route exists.
 */
return [

    /*
    |--------------------------------------------------------------------
    | Workspace key -> that workspace's own Overview route
    |--------------------------------------------------------------------
    | The Overview is the workspace's dashboard. It answers "what is
    | happening in THIS workspace", which is a different question from the
    | Global Company Dashboard ("what is happening in the company") and from
    | Management ("how is the company doing"). See ADR 045.
    */
    'overviews' => [
        'hse' => 'hse.dashboard',
        'hr' => 'hr.dashboard',
        'logistics' => 'logistics.dashboard',
        'management' => 'management.overview',
    ],

];
