# Moderation, removal and escalation

## Visitor reports

Every media page has a report form. It works without JavaScript and submits in place when JavaScript is available.

| Reason | Effect |
|---|---|
| Media is broken or unavailable | Media is queued for re-validation in the next background run |
| Copyright / removal request | Report opened; the moderation contact is emailed |
| Non-consensual content | **Post unpublished immediately**; report escalated; contact emailed |
| Involves a minor (suspected CSAM) | **Post unpublished immediately**; report escalated; contact emailed |
| Other illegal content / wrong label / other | Report opened |

Abuse controls:

* a honeypot field;
* at most 5 reports per hour per client;
* de-duplication per client, post and reason;
* only a keyed hash of the IP address is stored.

## Moderator actions (Reports & Moderation)

| Action | Effect |
|---|---|
| Resolve | Close the report; nothing else changes. |
| Dismiss | Close the report and restore the post if it was auto-hidden by this report type. |
| Escalate | Hide the post and notify the moderation contact. |
| Take down | Unpublish the post and block its GUID so it is never re-imported. The record is kept. |
| Restore | Republish the post. |
| Block source | Disable the feed, block its domain, and take the item down. |

**Removal requests by URL** (Source Restrictions → *Removal request*) take down every imported item with that canonical source URL and block the URL. Use this for copyright notices and disputed content. Every action is logged in the `moderation` channel.

## Suspected child sexual abuse material (CSAM)

The plugin hides the content immediately and **does not delete it**: deleting may destroy evidence you are legally required to preserve or report. Follow your legal obligations, for example:

* **US:** report to the NCMEC CyberTipline (18 U.S.C. § 2258A applies to electronic service providers);
* **UK:** report to the Internet Watch Foundation;
* **EU and elsewhere:** use your national INHOPE hotline or law enforcement.

After reporting, follow the authority's instructions on preservation, then remove the content and block the source. Never download, copy or share suspected material for "verification".

## Rights and attribution

* **Defaults:** excerpt-only text, remote embedding, and visible source attribution with links to the original.
* **Full-text republishing** and **local media storage** should only be enabled when you have permission. Record the licence or permission reference in the feed's *rights note*.
* **Provenance:** every post keeps its source URL, GUID, feed, discovery time and source date, shown in the *Import provenance* box on the editor screen.
