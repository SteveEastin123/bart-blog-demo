# Information Request for the Web Development Company

## Purpose

We are preparing the Ehrman Blog Discovery WordPress plugin for integration into Bart's production website. The information below will help us align the plugin with the production theme, technical environment, development standards, and deployment process before handing it over.

Providing this information should reduce the amount of design and integration work required from the development company and limit avoidable rework.

## WordPress Theme Integration Package

Please provide the following:

> Could you provide a development copy of the production WordPress theme, including any parent and child themes, along with its design system or style guide? Please also include documentation for reusable components, templates, hooks, custom blocks, and any page builder being used. Access to a staging environment would help us adapt the plugin's pages and controls to match the site accurately.

The theme integration package should include, when applicable:

- The current production theme source or theme ZIP
- Parent and child themes
- `theme.json` and `style.css`
- Theme templates and template parts
- Typography, color, spacing, and responsive-layout standards
- Reusable form, button, link, navigation, panel, and list components
- Custom hooks, filters, blocks, patterns, and shortcodes
- Page-builder information and development documentation
- Accessibility and browser-support requirements

## Production Technology

Please confirm the following production details:

- WordPress version
- PHP version and enabled extensions
- The database must be **Oracle MySQL, not MariaDB**
- Exact MySQL version, preferably MySQL 8.0 or newer
- Database character set and collation, preferably `utf8mb4`
- Whether custom plugin tables, indexes, and InnoDB transactions are permitted
- Whether WordPress Multisite is enabled
- Web server and hosting platform
- Object caching, page caching, CDN, and web application firewall products

## WordPress and Frontend Architecture

Please answer the following question:

> Is the production website rendered directly by WordPress, or does it use a headless or decoupled frontend? If it is conventional WordPress, are there any restrictions on custom PHP plugins, REST endpoints, WP-CLI commands, or dedicated MySQL tables?

Please also identify:

- Whether WordPress controls both content management and frontend rendering
- Any separate frontend framework, such as React or Next.js
- Any external application or API layer between WordPress and the public website
- Any existing search platform, such as Elasticsearch, OpenSearch, or Algolia
- Whether custom PHP plugins require architectural or security approval
- Whether the preferred integration uses shortcodes, WordPress blocks, theme templates, or REST APIs
- Any restrictions on direct database access through the WordPress database APIs

For a conventional WordPress site, PHP is the expected language for the plugin. JavaScript and CSS support the interactive interface, while PHP handles WordPress integration, MySQL queries, administration, REST endpoints, and page rendering. A headless frontend or an existing external search platform could require a different integration approach.

## Existing Post Structure

Please explain how production posts are stored and identified:

- Whether the indexed blog posts use the standard WordPress `post` post type
- Whether WordPress post IDs are stable and available for matching
- Whether any posts use custom post types
- How canonical post URLs are generated
- Whether post URLs have changed or may change during the redesign
- Whether authors, publication dates, visibility, and membership restrictions use standard WordPress fields or custom metadata
- Whether redirects exist for older post URLs

The discovery data can match records to production posts by WordPress ID, with URL matching as a fallback.

## Plugin Integration Requirements

Please identify any standards or restrictions governing custom plugins:

- Required WordPress and PHP coding standards
- Naming, namespace, database-prefix, or directory conventions
- Whether custom REST API routes are permitted
- Whether shortcodes, blocks, or template integrations are preferred
- Whether WP-CLI commands may be used for data importing and maintenance
- Approved and prohibited third-party libraries
- Required internationalization or translation support
- Required accessibility standard, preferably WCAG 2.1 AA or newer
- Supported browsers and mobile-device requirements

## Development and Staging Access

Please provide or arrange:

- A staging environment that reflects the production configuration
- A development copy of the theme and relevant custom plugins
- Sanitized sample data when necessary for integration testing
- Appropriate WordPress administrator, Git, deployment, and log access
- A secure method for sharing credentials; credentials should not be sent in ordinary email or stored in the source repository
- A technical contact who can answer theme and infrastructure questions

## Deployment and Maintenance Process

Please describe:

- The source-control and branching workflow
- Continuous integration and deployment requirements
- How custom plugin releases are packaged and deployed
- Required code-review and approval steps
- Staging-to-production promotion procedures
- Backup and rollback procedures
- Maintenance windows and release scheduling
- How future topic, category, keyword, and post-description updates should be delivered and imported
- Who will own ongoing plugin maintenance after launch

## Security and Performance Requirements

Please identify:

- Required security scanners and dependency-review tools
- Authentication and authorization requirements
- Data-retention, privacy, and logging requirements
- Traffic levels and expected search volume
- Database-query or response-time targets
- Rate limits or REST API restrictions
- Cache-invalidation requirements after discovery-data updates
- Required penetration, vulnerability, load, or performance testing

## Acceptance and Handoff

Please confirm:

- Who will approve the final user interface
- Who will perform production acceptance testing
- Which automated tests must pass before deployment
- Whether the development company will accept our parity tests and validation scripts
- What documentation must accompany the plugin
- Whether the company prefers an installable plugin ZIP, a Git repository, or both
- Whether a formal support or warranty period is required after launch

## Expected Benefit

With the production theme and technical requirements available in advance, we can deliver a plugin that already uses the site's typography, colors, spacing, responsive behavior, components, hooks, and coding conventions. The development company's work can then focus primarily on code review, production integration, testing, and deployment rather than redesigning the plugin interface.
