#!/bin/sh
set -e

# Runs before every role of the image (web, queue worker, scheduler).
#
# The configuration cache is built here and not at image build time: baking it
# would freeze the build machine's environment - its database password, its app
# key - into an artifact meant to run somewhere else entirely.
php artisan config:cache --quiet

# The web container, and only it, prepares what the whole installation shares.
if [ "${SAGRAFLOW_ROLE:-worker}" = "web" ]; then
    # --force is not "force the migration": it answers the confirmation Laravel
    # asks for in production, which nobody is here to answer. Left to the web
    # container alone because three containers starting together would all reach
    # for the same schema, and the two that lose have nothing useful to do about
    # it.
    php artisan migrate --force

    # public/storage -> storage/app/public, which is how the receipt logo becomes
    # reachable over HTTP. Printing does not need it (it reads the file off the
    # disk), so without this the logo comes out on the receipts and shows as a
    # broken image in the panel - a confusing way to find out.
    php artisan storage:link --force
fi

exec "$@"
