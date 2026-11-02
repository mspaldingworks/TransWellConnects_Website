# TransWellConnects_Website

Page code for [transwellconnects.com](https://transwellconnects.com).

The site runs on WordPress (Astra theme, Spectra blocks), which keeps pages in its database rather than in files. The files here are copies of that page code. Editing one does not change the site until the code is pasted back into the page in the WordPress editor.

## Pages

| File | Live page | How the page is built |
| --- | --- | --- |
| [pages/home.html](pages/home.html) | [Home](https://transwellconnects.com/) | Hand-written HTML, CSS and JavaScript in four Custom HTML blocks |
| [pages/volunteer-hub.html](pages/volunteer-hub.html) | [Volunteer Hub](https://transwellconnects.com/services/) | A Spectra banner, then one hand-written Custom HTML block |
| [pages/contact.html](pages/contact.html) | [Contact](https://transwellconnects.com/contact/) | Spectra blocks and a SureForms contact form |
| [pages/listening-session-11-19-2026.html](pages/listening-session-11-19-2026.html) | [Listening Session: 11/19/2026](https://transwellconnects.com/listening-session-11-19-2026/) | Standard WordPress paragraph, heading and list blocks |
| [pages/listening-session-responses.html](pages/listening-session-responses.html) | [Listening Session Responses](https://transwellconnects.com/listening-session-responses/) | One Custom HTML block, holding the page and both Transpire Feedback shortcodes |

Project Tracking (`/project-tracking/`) is password-protected on the site and is not in this repo.

The header, footer and menus are set in the Astra theme, not in any page, so they are not here either.

## Where each file came from

`home.html` is the code as written, before WordPress processes it.

The other three were saved on 2026-10-10 from what WordPress sends to visitors, not from the editor. That leaves a few marks:

- Apostrophes and quotes in text show up as codes such as `&#8217;`, and images carry `decoding="async"`. WordPress adds these on the way out. They are harmless if pasted back.
- Parts built with Spectra blocks (all of Contact, and the banner at the top of Volunteer Hub) get their layout and styling from block settings stored in WordPress. Pasting this HTML back will not recreate those blocks. Change them in the WordPress editor.
- In `contact.html` the form itself is replaced by a comment. The form is built under SureForms in WP Admin.

## Putting an edit on the site

1. Edit the file here and commit.
2. In WP Admin, open the page under Pages.
3. Click the Custom HTML block that holds the code you changed, replace its contents, and choose Update.

Which code goes in which block:

- **Home** has four Custom HTML blocks, in this order: the hero (starts at `<div class="transpire-hero">`), the event section (`<div class="transpire-event"`), the about section (`<div class="transpire-about">`), and the drop-off network map (from the `TRANSPIRE DROP-OFF NETWORK MAP` comment to the end of the file).
- **Listening Session Responses** has one Custom HTML block holding the whole file, shortcodes included. It is the audience's page on the day, styled after the event flyer.
- **Volunteer Hub** has one Custom HTML block. It holds everything from the `TRANSPIRE VOLUNTEER HUB v3` comment to the end of the file. The three lines above that comment are the Spectra banner and stay out of the paste.

## Transpire Feedback plugin

[plugins/transpire-feedback/](plugins/transpire-feedback/) is a WordPress plugin for Transpire listening sessions. Unlike the pages above, it is real code that runs on the site: these files are the plugin.

It gives the audience two things, each placed on a page with a Shortcode block:

- `[transpire_reactions]` shows emoji buttons to tap during the session. Each tap is saved with its time. The buttons are open only while an organizer is recording (below).
- `[transpire_feedback]` shows a written feedback form. It opens with the first Start and stays open for 14 days after the real session ends.

Organizers run it from WP Admin under **Transpire Feedback → Live session**, which has one button. **Start** opens the reaction buttons for everyone on the page (open phones notice within about 15 seconds) and shows a chart of the responses that redraws every 5 seconds. The same button becomes **Stop**, which closes the reactions and saves that start-to-stop as its own record. Each record has a report (a single page to read, print or download, with the timeline, the busiest moments, each reaction's share, the written feedback grouped by question, and reflection questions) and CSV downloads. Tick **Rehearsal** before Start to keep practice taps apart from the real results.

As a safety net, if nobody has pressed Start by the scheduled start time, recording starts by itself (a rehearsal still running then is stopped first). A run nobody stops closes itself after 4 hours, or after 24 hours for a rehearsal, so a demo can stay open all day.

**Results** shows everything for a session, or any one record. Under the buttons, everyone on the page sees a chart of the room's reactions over time (totals per reaction, never who sent what), refreshed every 10 seconds while a run is recording; once the session is over it shows the last real run.

Reactions and feedback are anonymous. No names, IP addresses or browser details are stored. Each device gets a random id, kept only as a scrambled code, so the plugin can pace taps and count devices. An email address is stored only if someone chooses to type one into the form.

### Installing or updating it

1. Zip the `transpire-feedback` folder.
2. In WP Admin, go to Plugins → Add Plugin → Upload Plugin, choose the zip, then Install and Activate. To update, upload the new zip the same way and choose to replace the current version.

With Advanced File Manager instead: upload the zip into `public_html/wp-content/plugins/`, extract it there, then activate **Transpire Feedback** under Plugins.

Results live in three database tables, `wp_transpire_reactions`, `wp_transpire_feedback` and `wp_transpire_runs` (with the site's own table prefix). Deactivating or deleting the plugin leaves them in place. Updating from 1.0 adds the runs table and a `run_id` column by itself; rows saved before then are kept.

### Settings

**Transpire Feedback → Settings** holds the session name, its start and end times and timezone, the list of reaction buttons, the feedback questions, and the automatic start. Rehearsals replace 1.0's test mode: a finished rehearsal can be removed from the Live session screen.
