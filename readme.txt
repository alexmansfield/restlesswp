=== RestlessWP ===
Contributors: sackclothlabs
Tags: rest-api, acf, automatic-css, mcp, abilities-api
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exposes REST API endpoints for WordPress plugin configurations not natively accessible via the WP REST API.

== Description ==

RestlessWP provides a modular, extensible REST API layer for WordPress plugins that lack external APIs. It lets AI agents (via MCP/Abilities API) and human developers manage plugin configurations programmatically instead of clicking through wp-admin or manipulating the database directly.

= Supported Integrations =

* **Advanced Custom Fields (ACF)** — Full CRUD for field groups, post types, and taxonomies
* **Automatic CSS (ACSS)** — Read/write access to CSS variables; read-only access to utility classes

= Key Features =

* **Modular architecture** — each supported plugin is a self-contained module
* **Capability mirroring** — every endpoint requires the same WordPress capability the target plugin requires in wp-admin; RestlessWP never grants access beyond what a user already has
* **Consistent response envelope** — all endpoints return `{ "success": true, "data": { ... } }` or `{ "success": false, "code": "...", "message": "..." }`
* **WordPress Abilities API integration** — all operations are registered as WordPress abilities, discoverable via the Abilities API and callable via MCP
* **Third-party extensibility** — add support for any WordPress plugin by extending the base classes and registering via the `restlesswp_modules` filter

== Installation ==

1. Download the plugin files and upload them to `/wp-content/plugins/restlesswp/`, or install directly from a ZIP archive via the WordPress admin.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. No settings page is required — the plugin activates cleanly even if no supported plugins (ACF, ACSS) are installed.
4. Set up authentication (see below) to start making API requests.

= Requirements =

* WordPress 6.9 or higher
* PHP 8.0 or higher
* For ACF endpoints: Advanced Custom Fields 6.7.0+ (free or Pro)
* For ACF post type/taxonomy endpoints: ACF 6.1+
* For ACSS endpoints: Automatic CSS 3.0.0+

== Authentication ==

RestlessWP uses WordPress Application Passwords for authentication. Application Passwords are built into WordPress core (since WP 5.6) and work with any HTTP client.

= Setup =

1. Go to **Users > Profile** in wp-admin.
2. Scroll to the **Application Passwords** section.
3. Enter an application name (e.g., "RestlessWP Client") and click **Add New Application Password**.
4. Copy the generated password — it will not be shown again.

= Making Requests =

Send requests with HTTP Basic Auth using your WordPress username and the application password:

`
curl -u "admin:XXXX XXXX XXXX XXXX XXXX XXXX" \
  https://example.com/wp-json/restlesswp/v1/acf/field-groups
`

The user you authenticate as must have the required capabilities for the endpoint (see the endpoint reference below).

== API Endpoint Reference ==

All endpoints use the `restlesswp/v1` namespace. The base URL is:

`
https://your-site.com/wp-json/restlesswp/v1/
`

= ACF Field Groups =

7 endpoints for managing ACF field groups and their fields.

* `GET /acf/field-groups` — List all field groups with fields and location rules. Requires `edit_posts`.
* `GET /acf/field-groups/{key}` — Get a single field group by key. Requires `edit_posts`.
* `POST /acf/field-groups` — Create a new field group. Requires `manage_options`. Returns 409 if a group with the same key exists.
* `PUT /acf/field-groups/{key}` — Partial update a field group (merges with existing data). Requires `manage_options`.
* `POST /acf/field-groups/{key}/fields` — Add a field to a field group. Requires `manage_options`.
* `PUT /acf/field-groups/{key}/fields/{field_key}` — Update a single field. Requires `manage_options`.
* `DELETE /acf/field-groups/{key}/fields/{field_key}` — Remove a field. Requires `manage_options`.

Requires ACF 6.7.0+.

= Post Types =

4 endpoints for listing all registered post types and managing ACF-created post types.

* `GET /post-types` — List post types. Supports `source` parameter: `all` (default) or `acf`. Requires `edit_posts`.
* `GET /post-types/{key}` — Get a single post type. Requires `edit_posts`.
* `POST /post-types` — Create an ACF-managed post type. Requires `manage_options`.
* `PUT /post-types/{key}` — Update an ACF-managed post type. Requires `manage_options`.

When `source=all`, each post type includes a `source` field indicating its origin: `acf`, `core`, a known plugin slug (e.g., `woocommerce`), or `unknown`.

Requires ACF 6.1+.

= Taxonomies =

4 endpoints for listing all registered taxonomies and managing ACF-created taxonomies.

* `GET /taxonomies` — List taxonomies. Supports `source` parameter: `all` (default) or `acf`. Requires `edit_posts`.
* `GET /taxonomies/{key}` — Get a single taxonomy. Requires `edit_posts`.
* `POST /taxonomies` — Create an ACF-managed taxonomy. Requires `manage_options`.
* `PUT /taxonomies/{key}` — Update an ACF-managed taxonomy. Requires `manage_options`.

Requires ACF 6.1+.

= ACSS Variables =

4 endpoints for managing Automatic CSS variable settings.

* `GET /acss/variables` — List all ACSS variables. Requires `manage_options`.
* `GET /acss/variables/{key}` — Get a single variable by key. Requires `manage_options`.
* `POST /acss/variables` — Create a new variable. Requires `manage_options`. Returns 409 if the key exists.
* `PUT /acss/variables/{key}` — Update a variable value. Requires `manage_options`.

Requires Automatic CSS 3.0.0+.

= ACSS Classes =

2 read-only endpoints for Automatic CSS utility classes.

* `GET /acss/classes` — List all utility classes. Requires `manage_options`.
* `GET /acss/classes/{name}` — Get a single class by name. Requires `manage_options`.

Classes are defined in ACSS's compiled SCSS sources and cannot be created or modified via the API.

Requires Automatic CSS 3.0.0+.

= Etch Templates =

5 endpoints for managing block templates (`wp_template` CPT) scoped to the active theme.

* `GET /etch/templates` — List all templates for the active theme (DB + theme-file). Optional `source` filter (`theme`, `plugin`, `custom`). Requires `edit_posts`.
* `GET /etch/templates/{id}` — Get a single template with content by post ID. Theme-file-only templates (no DB row) return 404. Requires `edit_posts`.
* `POST /etch/templates` — Create a new template. Required: `title`. Optional: `content`, `slug`. Auto-sets `wp_theme` taxonomy. Returns 409 if slug already exists for the theme. Requires `manage_options`.
* `PUT /etch/templates/{id}` — Partial update (title, content, slug). Requires `manage_options`.
* `DELETE /etch/templates/{id}` — Delete a template. Requires `manage_options`.

Requires Etch 1.0.0+.

== Response Format ==

All endpoints return a consistent JSON envelope.

= Success =

`
{
  "success": true,
  "data": { ... }
}
`

= Error =

`
{
  "success": false,
  "code": "not_found",
  "message": "The requested resource was not found."
}
`

== Error Codes ==

RestlessWP uses a standard set of error codes across all endpoints:

* `module_inactive` (HTTP 424) — The target plugin (ACF, ACSS) is not active on this site.
* `version_unsupported` (HTTP 424) — The target plugin version is too old for the requested operation.
* `not_found` (HTTP 404) — The requested resource was not found.
* `conflict` (HTTP 409) — A resource with the same key already exists (returned on POST create).
* `forbidden` (HTTP 403) — The authenticated user does not have the required capability.
* `validation_error` (HTTP 400) — The request body failed schema validation.

== Abilities API and MCP ==

RestlessWP registers all operations as WordPress abilities using the Abilities API (WP 6.9+). This means every endpoint is also discoverable and callable by AI agents via the Model Context Protocol (MCP).

Abilities are registered under the `restlesswp` namespace with the naming pattern:

`
restlesswp/{module}-{action}-{resource}
`

For example: `restlesswp/acf-list-field-groups`, `restlesswp/acss-get-variables`.

All abilities include:

* Typed input/output schemas derived from the controller's JSON Schema
* Operation annotations: `readonly` for GET, `open_world` for POST, `idempotent` for PUT, `destructive` for DELETE
* `show_in_rest => true` and `mcp.public => true` for MCP visibility

Discover available abilities via:

`
GET /wp-json/wp-abilities/v1/abilities
`

== Extensibility ==

RestlessWP is designed to be extended with support for additional WordPress plugins. You can create a custom module by:

1. Extending `RestlessWP_Base_Module` to declare the plugin dependency, module slug, and resources.
2. Extending `RestlessWP_Base_Controller` for each resource, implementing only the plugin-specific CRUD methods.
3. Registering your module via the `restlesswp_modules` filter.

Your module automatically inherits REST route registration, response envelope formatting, capability-mirrored permissions, conflict detection, partial update merging, and Abilities API registration.

For a complete guide with example code, see `docs/extending.md` in the plugin directory.

== Frequently Asked Questions ==

= Does RestlessWP create its own permission system? =

No. RestlessWP mirrors the exact same WordPress capabilities that the target plugin requires in wp-admin. If a user cannot perform an action through the admin UI, they cannot perform it through the API either.

= What happens if ACF or ACSS is not installed? =

The plugin activates without errors. Modules for inactive plugins are simply not loaded, and their endpoints are not registered. You will receive a `module_inactive` error if you attempt to call an endpoint for an inactive plugin.

= Does RestlessWP support multisite? =

Multisite is not supported in v1.

= Can I use this with WooCommerce or other plugins? =

Not out of the box. RestlessWP ships with ACF and ACSS modules. However, you can add support for any plugin by creating a custom module — see the Extensibility section above.

== Changelog ==

= 0.1.0 =

Initial release.

* Core framework: base module, base controller, module registry, auth handler, response formatter
* ACF integration: field groups (7 endpoints), post types (4 endpoints), taxonomies (4 endpoints)
* Automatic CSS integration: variables (4 endpoints), classes (2 read-only endpoints)
* Source detection for post types and taxonomies (core, ACF, known plugins, unknown)
* WordPress Abilities API integration with typed schemas and MCP support
* Third-party extensibility via `restlesswp_modules` filter
* Developer documentation and example module
* Etch integration: templates (5 endpoints) — theme-scoped CRUD on `wp_template` CPT
