# CakePHP Interact logo assets

`interact-mark.svg` is the icon-only logo used in the README, documentation
navigation, favicon, and homepage. Four small isometric islands connected in a
square represent independent PHP islands working together. Short bridges connect
the centers of the cube sides, leaving each top face intact. The bridges meet the
cube faces directly and disappear behind the nearer cubes to preserve depth.

The mark shares FormCompose's flat indigo faces: `#818cf8` on top, `#6366f1`
on the left, and `#4338ca` on the right. It works on light and dark backgrounds.

The SVG has a transparent background and editable paths. It contains no lettering,
embedded raster images, fonts, scripts, gradients, or filters. Preserve its square
aspect ratio and clear space when resizing it.

`interact-mark.png` is a 512 × 512 export for social sharing metadata. Regenerate
it from this directory with:

```sh
rsvg-convert --width 512 --height 512 interact-mark.svg --output interact-mark.png
```
