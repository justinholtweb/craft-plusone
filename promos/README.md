# Plugin Store promo images

Marketing images for the Plus One listing on the Craft Plugin Store, in the same theme as
[justinholt.com/plugins/craft-plusone](https://justinholt.com/plugins/craft-plusone).

```bash
./build.sh          # all slides
./build.sh "2 5"    # just slides 2 and 5
```

Output: `out/plusone-promo-N.jpg`, 1920×1080 (rendered at 2× in headless Chrome, downsampled).
**JPEG, never PNG.** `fonts.css` is generated and gitignored.

The cover and the closing slide carry the Craft 6 beta caution, and every footer says
"Craft CMS 6 (beta)" — keep it there until Craft 6 and Plus One leave beta.

The watermark is the "+1" **outline** (`assets/watermark.svg`, the stroked `resources/icon-mask.svg`).

| # | Slide |
| --- | --- |
| 1 | Cover — free, needs Craft Pro, Craft 6 beta caution |
| 2 | Any provider, from the control panel |
| 3 | Account-takeover guards |
| 4 | Buttons and accounts in Twig and Blade |
| 5 | Per site and headless |
| 6 | Closing — summary, beta caution |
