---
title: Connect Slack
description: Authorize a Slack channel to receive selected Studio events.
---

The Slack integration sends selected Studio events to a connected Slack channel. An administrator configures the Slack app once. Users with workspace management access authorize a destination from Studio.

## Configure the OAuth app

Enable incoming webhooks in the Slack app and add the exact callback URL exposed by your Studio installation. Start by publishing the optional migrations in the Laravel host:

```bash
php artisan vendor:publish --tag=media-studio-integration-migrations
php artisan migrate
php artisan route:list --path=slack
```

```dotenv
MEDIA_STUDIO_SLACK_ENABLED=true
MEDIA_STUDIO_SLACK_CLIENT_ID=your-slack-client-id
MEDIA_STUDIO_SLACK_CLIENT_SECRET=your-server-side-secret
MEDIA_STUDIO_SLACK_REDIRECT_URI=https://studio.example.com/the-registered-callback
```

Use the callback reported by `route:list` in place of the example path. Keep the client secret on the server. Rebuild Laravel's configuration cache after changing environment values.

## Choose what to send

1. Open **Integrations → Slack**, then choose the Studio workspace.
2. Connect through Slack and select the destination channel on the authorization screen.
3. Choose the events available for the connection and save. Test with an event you can verify in the authorized channel.
4. Disconnect from Studio to stop delivery to that destination.

If authorization returns to the wrong URL, compare the configured redirect URI, Slack's registered URI and the browser origin. If delivery stalls, verify the scheduler and integration status. Workspace permissions apply to setup and disconnection.
