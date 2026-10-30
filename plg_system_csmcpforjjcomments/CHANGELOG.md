# MCP for JComments — Changelog

## 🚀 Version 1.0.0 (October 29, 2026)

First release. 15 tools for **JComments** (`com_jcomments`, the exstreme/Jcomments-4 line for Joomla 4, 5
and 6). Studied against **JComments 5.0.6** and diffed against **5.0.5**. Driven end-to-end on Joomla
6.2.0 / PHP 8.3 with comments posted through JComments' own front-end form by guests and a registered
user, including nested replies and spam.

### ✨ New

- **Diagnostics** — `check_jcomments_health`, `get_jcomments_settings`, `query_jcomments_table`.
- **Reading comments** — `list_jcomments_comments` (newest first; filter by state, article, author, IP,
  date range, text, or the replies to one comment; optional spam score) and `get_jcomments_comment`
  (one comment with its whole thread, reports, author history and spam assessment).
- **Replying** — `create_jcomments_reply` posts a reply as the MCP token's own account, threaded exactly
  as JComments threads replies.
- **Moderation** — `set_jcomments_comment_state` (approve or unpublish, one or many) and
  `delete_jcomments_comments` (JComments' own delete, with a dry run).
- **Spam** — `check_jcomments_spam` (scores comments and names every rule that fired) and
  `mark_jcomments_spam` (unpublish or delete, and optionally ban the IP on JComments' blacklist).
- **Read-only lists** — `list_jcomments_objects` (commented articles with pending counts),
  `list_jcomments_reports`, `list_jcomments_subscriptions`, `list_jcomments_blacklist`,
  `list_jcomments_mail_queue`.

### 🏗 Architecture

- **Nothing emails anyone unless you ask.** JComments' own admin Publish button emails every subscriber
  of the article. Here, approving, replying, unpublishing, deleting and banning send nothing and fire no
  JComments plugin event by default. `notify: true` runs JComments' own notification code for that
  action, exactly as its admin or front end would.
- **Replies go through JComments' own comment table**, which computes the parent, thread, path and level
  columns from the comment being answered. A reply is refused rather than truncated when the thread is
  deeper than those columns hold, and an identical reply to the same comment is refused, so a retry
  cannot post twice.
- **Deletes go through JComments' own table too** and follow its delete mode: "delete" removes the
  comment and every reply beneath it with their votes and reports; "mark" leaves a "comment deleted"
  placeholder and keeps the replies.
- **Every spam flag explains itself**: links and their domains, known spam phrases, the site's own
  JComments censor list, a link in the author name, disposable email, repeated or shouted text, the same
  text posted elsewhere, a burst from one IP, blacklist hits, new authors, reader reports and poor votes.
  No rule looks at the language or script of the text.
- Unsubscribe hashes and mail bodies (which carry moderation links) are never shown.
- **Free**, because JComments is free.

### 📝 JComments behaviour worth knowing

- A fresh install enables comments in **no** category, so comments appear only on articles containing
  `{jcomments on}` until categories are chosen in JComments' settings. The health check says so.
- A fresh install uses the flat "list" view: no Reply button for visitors, replies shown in date order.
- Several permissions work the opposite way to their names: allowing "comment.captcha", "comment.flood",
  "comment.length_check" or "comment.terms_of_use" EXEMPTS a group from that check.
- Subscriber mail does not depend on the "enable notification" option, which only covers moderator mail.
- An IP ban never stops a logged-in visitor: JComments checks them by user id only. Bans have an expiry
  date field that nothing reads.
- On Joomla 6 without the "Behaviour - Backward Compatibility 6" plugin, saving an entry in JComments'
  admin Blacklist form stops with a blank page. The health check detects it; banning through
  `mark_jcomments_spam` is unaffected.

### 📦 Build

- Requires PHP 8.2+ and CS MCP for J. Installs disabled, with an explanation, when CS MCP for J is not
  present, and clears Joomla's caches after install.
