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
- **Volunteer Hub** has one Custom HTML block. It holds everything from the `TRANSPIRE VOLUNTEER HUB v3` comment to the end of the file. The three lines above that comment are the Spectra banner and stay out of the paste.
