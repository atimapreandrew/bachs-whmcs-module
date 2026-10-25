# Third-party notices

The shipped PHP runtime contains no bundled third-party PHP or JavaScript libraries. It uses PHP extensions and the merchant’s existing WHMCS installation. Bachs and WHMCS remain separate products subject to their own terms.

The overlay loads `https://checkout.bachs.io/bachs.js` only after the customer selects an overlay checkout. It is served by Bachs and is not vendored in this package. Hosted checkout and customer portal pages remain provider services.

The module uses a text wordmark, system fonts and locally authored CSS; no official logo, stock imagery or externally hosted font is included. Names identify integration targets and imply no endorsement.

Official WHMCS examples were consulted to establish gateway and remote-card helper contracts. Runtime implementation and tests are independently authored; the examples’ unverified browser-success logic was not adopted.
