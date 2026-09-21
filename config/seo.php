<?php

/*
|--------------------------------------------------------------------------
| Public search identity (v2.75.0)
|--------------------------------------------------------------------------
|
| THE ONE LIST OF PAGES A SEARCH ENGINE MAY INDEX, and what each one says
| about itself. Read by the root Blade layout (title, description,
| canonical, social cards, structured data), by the sitemap, by the
| X-Robots-Tag middleware and by the browser-tab title in app.jsx -- so a
| page's metadata cannot disagree with whether it is indexable, and adding
| a page to the sitemap without describing it is impossible.
|
| AN ALLOW-LIST, deliberately. Every route NOT named here is sent
| `noindex`: sign-in, registration, the account area, subscription setup,
| every token-scoped order page, and the whole authenticated product. A
| new route is private until someone decides otherwise, which is the safe
| direction to be wrong in.
|
| Canonical URLs are built from config('ioms.public_url'), never from the
| request, so the legacy host and development machines can never become
| the canonical address. See docs/ADR/039-public-search-identity.md.
|
| Copy rules: IOMS is the product name and is never expanded. The
| descriptor is "Industrial Operations Platform". Natural sentences, one
| idea per page, no keyword lists. Titles stay under ~60 characters and
| descriptions under ~160 so neither is truncated in results.
|
| Legal pages are Indonesian documents, so their descriptions are
| Indonesian and the layout sets lang="id" for them (`lang` below).
*/

return [

    'pages' => [
        'home' => [
            'title' => 'IOMS — Industrial Operations Platform',
            'description' => 'IOMS brings safety, permits, people, assets and procurement into one platform for shipyards, construction, manufacturing, mining, marine and energy operations.',
            'priority' => '1.0',
            'changefreq' => 'weekly',
        ],
        'platform-overview' => [
            'title' => 'Platform Overview — IOMS',
            'description' => 'One platform for industrial operations: HSE and permits to work, workforce, projects, logistics, procurement and maintenance, sharing the same records and approvals.',
            'priority' => '0.9',
            'changefreq' => 'monthly',
        ],
        'solutions' => [
            'title' => 'Solutions for Industrial Operations — IOMS',
            'description' => 'How IOMS supports shipyards, construction sites, manufacturing plants, mines and energy operations — built around the way heavy industry actually runs work.',
            'priority' => '0.9',
            'changefreq' => 'monthly',
        ],
        'how-it-works' => [
            'title' => 'How It Works — IOMS',
            'description' => 'How IOMS works, from master data set up once to field and office teams working from the same records, through to the reporting management relies on.',
            'priority' => '0.8',
            'changefreq' => 'monthly',
        ],
        'pricing' => [
            'title' => 'Pricing and Plans — IOMS',
            'description' => 'Compare IOMS plans for industrial organizations, from a single department to the whole operation. Monthly or annual billing, with published prices.',
            'priority' => '0.9',
            'changefreq' => 'weekly',
        ],
        'faq' => [
            'title' => 'Frequently Asked Questions — IOMS',
            'description' => 'Plain answers about IOMS plans, capacity, payment, activation, how each organization\'s data is kept separate, and the documents IOMS produces.',
            'priority' => '0.7',
            'changefreq' => 'monthly',
        ],
        'contact' => [
            'title' => 'Contact — IOMS',
            'description' => 'Talk to the IOMS team about your industrial operation, a plan, billing or support.',
            'priority' => '0.6',
            'changefreq' => 'yearly',
        ],
        'legal.privacy' => [
            'title' => 'Privacy Policy — IOMS',
            'description' => 'Kebijakan Privasi IOMS: data apa yang kami kumpulkan, bagaimana data digunakan dan dilindungi, serta hak Anda atas data tersebut.',
            'priority' => '0.3',
            'changefreq' => 'yearly',
            'lang' => 'id',
        ],
        'legal.terms' => [
            'title' => 'Terms of Service — IOMS',
            'description' => 'Syarat dan Ketentuan penggunaan IOMS, termasuk akun, langganan, pembayaran, dan tanggung jawab para pihak.',
            'priority' => '0.3',
            'changefreq' => 'yearly',
            'lang' => 'id',
        ],
        'legal.refunds' => [
            'title' => 'Refund Policy — IOMS',
            'description' => 'Kebijakan Pengembalian Dana IOMS untuk langganan dan pembayaran.',
            'priority' => '0.3',
            'changefreq' => 'yearly',
            'lang' => 'id',
        ],
    ],

];
