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
| [pages/pride-and-joy-boxes.html](pages/pride-and-joy-boxes.html) | [Pride and Joy Boxes](https://transwellconnects.com/1861-2/) | One Custom HTML block holding the whole file; its three forms save through the Pride and Joy Boxes plugin |
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
- **Pride and Joy Boxes** has one Custom HTML block holding the whole file. Its three forms (request a box, offer to help, how was your box?) save through the Pride and Joy Boxes plugin (below), so install plugin 1.1.0 before pasting this version. If something cannot be saved, the form opens the visitor's email app addressed to `REQUEST_EMAIL` near the top of the script instead. Replace the yellow `[bracketed]` placeholders (class `pjb-todo`: price, how often boxes go out, who can get one, how subscribers pay) when those are decided.

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

## Pride and Joy Boxes plugin

[plugins/pride-joy-boxes/](plugins/pride-joy-boxes/) is a WordPress plugin that tracks Pride and Joy Boxes: who asks for boxes, who gives, and everything sent back and forth. Like Transpire Feedback, these files are the plugin.

The [Pride and Joy Boxes](https://transwellconnects.com/1861-2/) page and the Pride & Joy tab in the TransWell app both send to it, so everything lands in one tracker whichever way it was sent:

| Form | Route | Saved as |
|---|---|---|
| Request a box | `POST /wp-json/pride-joy/v1/requests` | a request |
| Offer to help (art, items, notes, money, time) | `POST /wp-json/pride-joy/v1/offers` | one donation per thing offered, marked Offered |
| How was your box? | `POST /wp-json/pride-joy/v1/feedback` | feedback, linked to the person and their latest request when they give an email |

### In WP Admin

Everything is under **Pride & Joy Boxes**, for site administrators:

- **Requests**: what each person asked for. Change the status, or record a box as sent with what went in, the cost and tracking.
- **People**: one record per person, matched by email, with their roles (asked for a box, donor, artist, note writer, volunteer). A person's page shows all their requests, boxes, donations, receipts, thank-yous, feedback and an activity log, and has **Forget this person** for when someone asks to be removed.
- **Donations**: gifts of money, art, items, notes or time, entered by the team or offered through the forms. Marking one Received lets you write its receipt.
- **Feedback**: what people said about their box, with whether they agreed to be quoted and how to credit them.
- **Receipts**: numbered PJB-year-n. Each keeps the exact text it was written with. Email or print it, then mark it sent.
- **Receipt template**: the wording for new receipts. Saving a change starts a new version; receipts already written keep theirs. The default says the gift is not tax-deductible, because Transpire is not a 501(c)(3). Change it when that changes (or when a fiscal sponsor issues receipts).

**Download Excel** on every screen gives a workbook of everything, built fresh each time: a Read me sheet, then People, Requests, Shipments, Donations, Receipts, Thank-yous, Feedback and Activity. Every row has a stable ID (C-0001 for people, R- requests, S- shipments, D- donations, T- thank-yous, F- feedback, A- activity), and the other sheets point to people by `person_id`. To move to a CRM later, import People first keeping the C- ID as an external ID, then the rest. The download needs the PHP zip extension, which Hostinger turns on by default.

### Notices and privacy

Each new request, offer or feedback sends a short notice to `transpire@transwellconnects.com`. It says only what arrived; names, addresses and sizes stay in WP Admin. No IP addresses or browser details are stored. The public routes refuse more than 20 posts an hour from one network, and quietly drop posts that fill a hidden field people never see.

### Installing or updating it

Zip the `pride-joy-boxes` folder and upload it under Plugins → Add Plugin → Upload Plugin, choosing to replace the current version when updating. Upload through that screen, not a file manager: it unpacks every file together. Updating from 1.0 keeps every request and gives each one a person, matched by email.

The data lives in the plugin's own tables (`wp_pjb_people`, `wp_pjb_requests`, `wp_pjb_shipments`, `wp_pjb_donations`, `wp_pjb_receipts`, `wp_pjb_thanks`, `wp_pjb_feedback`, `wp_pjb_activity`, with the site's own prefix). Deactivating or deleting the plugin leaves them in place.
