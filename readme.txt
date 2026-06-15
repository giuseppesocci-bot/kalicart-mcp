=== KaliCart MCP ===
Contributors: kalicart
Tags: mcp, ai, agents, markdown, content
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.2.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your WordPress site readable and callable by AI agents. Exposes your editorial content as an MCP server, plus clean Markdown and discovery.

== Description ==

KaliCart MCP turns a WordPress site into something AI agents can browse and query directly. It exposes your editorial content (posts, pages, and public custom post types) as a standards-based **Model Context Protocol (MCP) server** over JSON-RPC 2.0, alongside clean Markdown output and a lightweight presence/discovery layer.

It is **read-only** and self-contained: no external calls, no LLM, no cloud dependency. Everything runs on your own server. An agent connects to a single endpoint on your site and can list, search, and read your content as structured data.

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
