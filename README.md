# Lenarys Analytics

Lenarys Analytics is a Moodle local plugin for building, managing, and delivering report-based analytics. Its Moodle component name is `local_la`; the `la` suffix represents **Lenarys**.

The plugin provides a report and app library, controlled report audiences, scheduled exports, audit logs, optional learning-time tracking, calendar drilldowns, and AI-assisted report generation through Moodle's AI subsystem.

## Features

- Install and manage reports and dashboard-style apps from the Lenarys Marketplace.
- Review report definitions, SQL validation results, columns, widgets, and preview data before installation.
- Control report visibility with user, role, and all-user audience rules.
- Deliver report exports in the Moodle data formats enabled on the site, using one-time or recurring schedules.
- Review report access and audit activity.
- Track learning time and visits by course, activity, and user when tracking is enabled.
- Generate and edit report definitions with a configured Moodle AI provider.

## Requirements

- This `MOODLE_403_STABLE` branch is for Moodle 4.3.12 or later in the Moodle 4.3 release line.
- A PHP version supported by the installed Moodle release.
- Moodle cron configured and running for scheduled report delivery.
- No additional Moodle plugins are required.

AI-assisted report generation is available on Moodle 4.5 or later and requires a configured Moodle AI provider with the **Generate text** action enabled.

## Moodle version compatibility

Moodle releases are supported in separate plugin branches because the required Moodle APIs differ between release lines. Install the package that matches the Moodle version running on the site:

| Moodle release line | Minimum Moodle version | Plugin branch |
| --- | --- | --- |
| Moodle 5.0–5.2 | Moodle 5.0 | `main` |
| Moodle 4.5 | Moodle 4.5 | `MOODLE_405_STABLE` |
| Moodle 4.3 | Moodle 4.3.12 | `MOODLE_403_STABLE` |
| Moodle 4.2 | Moodle 4.2.11 | `MOODLE_402_STABLE` |
| Moodle 4.0 | Moodle 4.0.12 | `MOODLE_400_STABLE` |

The `main` branch targets Moodle 5.0 through 5.2. Moodle 4.5 sites must use `MOODLE_405_STABLE`. Do not install `main` or a package from another Moodle release branch on an older site. Moodle versions not listed in the table are not currently supported.

Moodle Marketplace installations should use the plugin release explicitly marked as compatible with the site's Moodle version. When installing from source control, check out the matching `MOODLE_*_STABLE` branch before creating or installing the ZIP package.

## Installation

### From a ZIP package

1. Confirm that the package supports the site's Moodle version using the compatibility table above.
2. In Moodle, go to **Site administration > Plugins > Install plugins**.
3. Upload the plugin ZIP package and select **Local plugin** if Moodle asks for the plugin type.
4. Complete the validation and installation steps.

### Manual installation

1. Check out or download the branch matching the site's Moodle version.
2. Extract the plugin into `<moodle-root>/local/la`.
3. Go to **Site administration > Notifications**, or run:

   ```bash
   php admin/cli/upgrade.php
   ```

4. Complete the installation prompts.

## Initial configuration

Go to **Site administration > Plugins > Local plugins > Lenarys Analytics** and:

1. Select the administrator responsible for billing and Lenarys Marketplace access.
2. Choose a license mode:
   - **API** connects to the configured Lenarys API service.
   - **Manual** reads a license JSON file supplied by the service provider.
3. Enable learning-time tracking only if it is appropriate for the site's privacy policy.
4. Review report audiences and the users who hold management access.
5. Confirm that Moodle cron and outbound email are working before enabling scheduled deliveries.

## Capabilities and access

- `local/la:manage` allows report management. It is granted by default to managers.

Report audiences control access to individual reports. Lenarys Marketplace, billing, audience administration, access review, and audit functions are restricted to users with management access or to the configured billing administrator.

Scheduled reports are sent only to eligible, email-enabled audience members. An **All users** scheduled audience is limited to 100 eligible recipients by default.

## Licensing and external services

Access to the Lenarys Marketplace and licensed features requires an active subscription purchased and managed through Moodle Marketplace. The plugin does not provide a checkout flow. Contact [Lenarys support](https://lenarys.com/support) for manual licensing assistance.

In API mode, the plugin communicates with the API endpoint configured by the site administrator. These requests can include:

- the license key;
- the Moodle site URL;
- the Moodle and plugin versions;
- the identifier of an installed report or app.

The Lenarys API is not sent Moodle user records, course content, report result rows, scheduled report attachments, or learning-time records.

AI-assisted report generation is separate from the Lenarys API. When an administrator uses it, the request is processed by the Moodle AI provider configured for the site. The provider may receive the administrator's prompt, selected report definitions and SQL, and selected Moodle table and column names. Administrators should review the privacy and data-processing terms of their chosen AI provider and avoid entering unnecessary personal data in prompts.

## Privacy

Depending on the features used, the plugin stores:

- report preferences and direct audience assignments;
- schedule configuration and delivery state;
- audit entries, including user and IP information;
- AI prompts, report context, responses, and generated definitions; and
- learning-time and visit aggregates, including page identifiers and browser metadata, when tracking is enabled.

Learning-time tracking is disabled by default. The plugin implements Moodle's Privacy API for discovery, export, and deletion of plugin-owned personal data. Scheduled exports may contain personal data selected by the report definition and are delivered through the site's configured Moodle email service.

## Support

- [Documentation and support](https://lenarys.com/support)
- [Report a bug or request a feature](https://github.com/len-arys/moodle-local_la/issues)
- Email: support@lenarys.com

When reporting an issue, include the Moodle version, plugin version, database type, relevant debugging output, and steps to reproduce. Do not include license keys or personal data.

## License

This plugin is licensed under the [GNU GPL v3 or later](LICENSE).
