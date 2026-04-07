# IonCube Encoding — Layer 4 Protection

## What this does
Compiles all PHP source files in `src/` into bytecode that cannot be
read or modified. Requires IonCube Loader on the host server (free).

## Steps to produce a release

1. Purchase IonCube Encoder (one-time cost):
   https://www.ioncube.com/encoder.php

2. Install on your build server:
   /usr/local/ioncube/ioncube_encoder.php8

3. Run the build script:
   ./build/ioncube/encode.sh

4. The encoded package is in dist/
   Ship dist/ to clients instead of the raw src/

## What clients need (free)
Clients only need the IonCube Loader PHP extension installed:
   https://www.ioncube.com/loaders.php

This is a standard extension — most hosting providers have it.

## Why this matters
Without encoding, a developer can:
- Read LicenseClient.php and find the portal URL
- Remove the check() call
- Fake a valid response locally

With IonCube encoding:
- Source is unreadable bytecode
- Cannot be modified
- Tied to a license file you control
