Generally we use a different version from the main one provided from https://github.com/MyIntervals/PHP-CSS-Parser, but still we try to upgrade ours to keep up with the latest changes and optimizations.

The current version that we use is from branch https://github.com/NitroPack/PHP-CSS-Parser/tree/patched-2024 as of 21.10.2025.

Very soon we plan to upgrade to https://github.com/NitroPack/PHP-CSS-Parser/tree/patched-2025.

How do we update and prepare the new version.

1. We do a catch up with upstream changes https://github.com/NitroPack/PHP-CSS-Parser/pull/6. We pull the changes from MyIntervals/main to Nitropack/master.
Usually that should happen without conflicts, as we keep the our master 1:1 with upstream main.

2. Create a new branch from Nitropack/master that will be your base for the new upgrade, we use 'patched-*' and provide the year (e.g. patched-2025), if it's the only upgrade for the year. We can be more specific with the date, if there are multiple upgrades over the year.

3. Apply changes from the diff of the previous upgrade with the master branch (e.g. https://github.com/NitroPack/PHP-CSS-Parser/compare/master...NitroPack:PHP-CSS-Parser:patched-2025). Run the ParserTest.php after each small change to verify.