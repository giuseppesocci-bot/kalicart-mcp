=== KaliCart MCP ===
Contributors: carthub
Tags: mcp, ai, chatgpt, claude, ai search
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.2.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your WordPress site readable by AI assistants. An MCP server that lets ChatGPT, Claude, Gemini and Perplexity find, search and read your content.

== Description ==

**Help AI assistants find, read and cite your WordPress content.** KaliCart MCP makes your WordPress site agent-ready: it turns your editorial content into something AI agents and assistants — such as ChatGPT, Claude, Gemini, Perplexity and any MCP-capable client — can browse, search and read directly. It exposes your posts, pages and public custom post types as a standards-based **Model Context Protocol (MCP) server** over JSON-RPC 2.0, alongside clean Markdown output and a lightweight presence/discovery layer. No LLM, no cloud, no external calls — everything runs on your own server.

It is **read-only**: an agent connects to a single endpoint on your site and can list, search, and read your published content as structured data — it never creates, edits, or deletes anything.

= What it does =

* Serves a JSON-RPC 2.0 MCP endpoint at `/wp-json/kalicart-mcp/v1/mcp`.
* Exposes five tools: site_info, site_map, list_content, search_content, get_content.
* Converts post content to clean GitHub-Flavored Markdown, including tables.
* Advertises itself through a `<link rel>` tag, a `/.well-known/kalicart-mcp` discovery document, a Content-Signal header, and robots.txt entries.

= Content stays under your control =

Exclusion works on three levels, all enforced:

1. **Structural** - attachments and commerce objects (products, variations) are never exposed. MCP is for content, not commerce.
2. **WooCommerce functional pages** - cart, checkout, my account, shop, and policy pages are excluded automatically as application UI rather than editorial content.
3. **Per-item** - a "Hide from AI agents" checkbox in the editor lets you remove any single post or page.

You choose which post types are exposed from a simple toggle in the admin screen.

= WooCommerce coexistence =

KaliCart MCP is the content companion to the KaliCart Bridge commerce plugin. The two are fully independent - separate namespaces, separate code - and can run side by side. MCP handles editorial content; Bridge handles products and commerce.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/kalicart-mcp`, or install through the Plugins screen in WordPress.
2. Activate the plugin through the Plugins screen.
3. Open the **KaliCart MCP** menu to see your endpoint, choose which content types to expose, and copy the ready-to-paste Claude Desktop configuration.

== Frequently Asked Questions ==

= How is this different from the official WordPress MCP Adapter? =

They do opposite jobs, and a site can use both.

The official MCP Adapter (built into WordPress 7.0) lets an AI agent manage your site for you — create drafts, update posts, moderate comments — after you log in and authorize it through a connector. It is built for the site owner operating their own site through an AI assistant.

KaliCart MCP needs no connector and no login. You install it, and it makes your published content discoverable and readable by AI assistants and agentic search — the way search engine indexing made your site findable on Google. It manages the surface of data your site exposes to AI: ChatGPT, Claude, Gemini, Perplexity and any agent can find, read and cite your pages. It only ever reads, and only your editorial content — never your admin, settings, or commerce data.

In short: the official adapter is the control panel an agent uses to run your site; KaliCart MCP is the index an agent reads to answer questions about your site — the SERP for agentic search. Many sites will want both.

= How do I make my WordPress site readable by AI assistants like ChatGPT and Claude? =

Install KaliCart MCP and activate it. It exposes your posts and pages through a Model Context Protocol (MCP) server — the standard interface AI assistants use to read external content. Open the KaliCart MCP admin screen, choose which content types to expose, and connect any MCP-capable client using the ready-to-paste configuration.

= What is an MCP server for WordPress? =

MCP (Model Context Protocol) is an open standard that lets AI assistants connect to external data sources. An MCP server for WordPress turns your site into a source that assistants like Claude, ChatGPT, Gemini and Perplexity can browse, search and read directly, instead of relying on scraping or guesswork.

= Does it work with Claude, ChatGPT, Gemini and Perplexity? =

Yes. KaliCart MCP implements the standard Model Context Protocol over JSON-RPC 2.0, so any MCP-compatible client or assistant can connect. The admin screen includes a copy-paste configuration for Claude Desktop using mcp-remote.

= Will this help my content appear in AI search and AI answers? =

It makes your content accessible to AI agents in a clean, structured form, which is the prerequisite for an assistant to read and cite it accurately. The plugin makes your content readable; it does not promise rankings or placement, which no plugin can guarantee.

= Does this send my content to any external service? =

No. The plugin makes no outbound calls. It only responds to agents that connect to your own endpoint. There is no LLM and no cloud component.

= Is it read-only? =

Yes. Agents can read, list, and search your published content. The plugin never creates, edits, or deletes anything.

= How do I connect an agent? =

The admin screen provides a copy-paste Claude Desktop configuration using mcp-remote. Any MCP-compatible client can connect to the endpoint at `/wp-json/kalicart-mcp/v1/mcp`.

= Will my products show up? =

No. Products, variations, attachments, and WooCommerce functional pages are excluded automatically. MCP exposes editorial content only.

= Can I hide a specific page? =

Yes. Each post and page has a "Hide from AI agents" checkbox in the editor sidebar.

= Do I need WooCommerce? =

No. WooCommerce is optional. If it is installed, the relevant functional pages are detected and excluded automatically.

== Changelog ==

= 0.2.11 =
* Content: site_info now lists the actual terms (name and slug) of each taxonomy, not just the count, so an agent can filter list_content by category or tag without guessing slugs. Capped at 100 terms per taxonomy.
* Content: list_content and search_content accept optional after / before publish-date bounds (ISO 8601 or YYYY-MM-DD), so agents can scope results to a time window.
* Content: list and search results now include each item's word_count, a length signal that lets an agent choose which item to open without fetching each one. The same count is reported in get_content, computed from source content so it matches everywhere.
* Admin: optional "Expose author name" toggle (off by default). When enabled, the post author's display name is included in list and content results.
* Docs: tool descriptions now reflect every field each tool returns (taxonomy term values, date filtering, word count, author), so agents know what to expect before calling.

= 0.2.10 =
* Compliance: admin page styles and scripts are now enqueued via wp_enqueue_style / wp_enqueue_script on admin_enqueue_scripts (scoped to the plugin's own screen) instead of being printed inline. Dynamic values reach the script through wp_localize_script.
* Compliance: unified every plugin-owned identifier (options, post meta, query var, nonces, classes, constants) under a single kcmcp_ prefix. No new behavior; no database migration.
* Robustness: the admin category list and the site_info term counts now restrict to the primary language through the same multilingual helper used by the content tools, so WPML sites (not only Polylang) show one language. No-op on monolingual sites.

= 0.2.9 =
* Multilingual support: on sites running a multilingual plugin (Polylang or WPML), KaliCart MCP now serves content in the site's primary language only. This removes duplicate posts, pages and categories from listings, search and the site map, so agents receive one clean, coherent set of content instead of the same item repeated once per language. The served language is declared in the JSON (language / served_language) so agents never have to guess; agents translate on demand for the end user.
* Admin: the category exclusion list now shows only primary-language categories (no more ambiguous per-language duplicates), with an explanatory notice on multilingual sites. Monolingual sites are unaffected.
* Content: list and search results now include each item's taxonomy terms (categories, tags), so the JSON is self-contained — an agent sees an article's category without a follow-up request.

= 0.2.8 =
* Hardening: reviewed all REST routes for WordPress.org compliance. Read-only public MCP endpoints declare an explicit public permission_callback (__return_true); admin endpoints require manage_options plus a per-item capability check and a wp_rest nonce. No behavioral change.
* Maintenance: removed the manual load_plugin_textdomain() call (translations are loaded automatically for plugins hosted on WordPress.org since WP 4.6).

= 0.2.7 =
* Fix: site_map no longer surfaces WooCommerce navigation (shop, product categories/tags); menu filtering matches linked objects, not URL slugs, so it is language-independent.
* Fix: list_content returns an explicit error for a non-exposed post_type instead of silently falling back to posts.
* Add: when WooCommerce is detected, the initialize instructions state the content-only policy and point agents to KaliCart Bridge for catalog data.

= 0.2.6 =
* Admin: finalized the KaliCart MCP brand logo in the page header.
* Maintenance: corrected the readme Contributors entry.

= 0.2.5 =
* Admin: new KaliCart MCP brand logo in the page header.

= 0.2.4 =
* Fix: restore toggle switch styling (a clipping rule was hiding the switch track); switch styles are now unified across both toggle sections.

= 0.2.3 =
* Fix: the "Content exposure" toggles now react only to the switch, not to clicks on the type label — same scheme as the exclusion list.

= 0.2.2 =
* Fix: clicking a row's title no longer toggles it — the hidden checkbox is now confined to the switch control instead of overflowing the whole row.

= 0.2.1 =
* Fix: sidebar menu icon now uses the same inline-SVG scheme as KaliCart Bridge (correct alignment, no CSS mask).
* Fix: exclusion row toggles switch only via the switch control, not by clicking the row.

= 0.2.0 =
* Admin: content exclusion controls — hide categories, individual posts and pages from AI agents via instant toggles, plus a per-item "Hide from AI agents" switch in the editor sidebar.
* Admin: unified post/page search with AJAX toggles and a live-updating "currently hidden" list.
* WooCommerce functional pages (cart, checkout, account, shop) are now excluded from agent-readable content automatically.
* Internationalization: Italian, German, French and Spanish translations; translation loader added.
* Fix: row toggles now switch only when the switch itself is clicked, not the whole row.
* Fix: sidebar menu icon alignment.

= 0.1.0 =
* Initial release.
* JSON-RPC 2.0 MCP server with five tools (site_info, site_map, list_content, search_content, get_content).
* Content data layer with three-level exclusion (structural, WooCommerce functional pages, per-item opt-out).
* HTML to GitHub-Flavored Markdown conversion, including tables.
* Presence and discovery layer: link rel, .well-known document, Content-Signal header, robots.txt entries.
* Admin screen: server status, content-exposure toggles, endpoint with copy, Claude Desktop config, WooCommerce coexistence card.
* Per-item "Hide from AI agents" control in the editor.

== Upgrade Notice ==

= 0.2.11 =
Richer agent navigation: taxonomy terms with slugs in site_info, publish-date filtering, word counts, and an optional author toggle.

= 0.2.5 =
New admin header logo.

= 0.2.4 =
Restores the toggle switch appearance.

= 0.2.3 =
Completes the toggle click-area fix for the content exposure section.

= 0.2.2 =
Fixes the exclusion toggle reacting to clicks on the whole row.

= 0.2.1 =
Minor admin UI fixes (menu icon and toggle behavior).

= 0.2.0 =
Adds content exclusion controls, automatic WooCommerce page exclusion, and IT/DE/FR/ES translations.

= 0.1.0 =
Initial release.
