---
title: Publish a collection or invite reviewers
description: Choose between public links, indexed publishing and verified-email invitations.
---

Use **Share** for an ordinary link. Use **Publish** for assets you want to distribute publicly with explicit indexing or copy settings. Use an email invitation when access should depend on the recipient's verified address. An invitation to review a collection is separate from [workspace membership](/members).

## Publish selected assets

Open **Publish** in the share dialog. Choose a display name and set the download policy before copying the link. Search engine indexing is optional and is unavailable with a link password. For example, publish a reviewed press kit with downloads enabled and indexing disabled. Allow copying into Studio only if you want signed-in recipients with access to a writable destination to be offered that action.

A published page still depends on the link state and the creator's access. Unpublish or revoke the link when distribution ends. For engagement reporting, see [Share and publish analytics](/share-analytics).

## Invite reviewers by email

Use the share invitation flow and select the required permission.

| Permission | Recipient actions |
| --- | --- |
| View | Open the accessible collection using the matching signed-in, verified address. |
| Comment | Add a comment to the shared page. This discussion is separate from internal workspace file comments. |
| Edit | Also expose supported edits, such as renaming an editable file when the delegated share permissions allow it. |

Configure the application's [mail delivery](/standalone-accounts#smtp-and-host-mail-integration) before sending invitations. Test with a verified account and a different account. The second account should be denied even if it received the link. To report on anonymous link access, use the regular analytics workflow rather than treating invitation emails as visit records.
