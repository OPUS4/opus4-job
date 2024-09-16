# opus4-job

Background processing for OPUS 4


## Testing

First you need to `install` or `update` the Composer dependencies.

    $ composer install 

The unit tests of **opus4-job** require a OPUS 4 database. You can create it using the Framework `opus4db` tool.

    $ vendor/bin/opus4db -d --dbname opus4job

The name of the database can be freely chosen.

The setup can be simplified by using the following two environment variables to specify the database users that should
be used for testing across all the OPUS 4 packages and their connected database schemas. This should only be used for
development.

    OPUS4_DEV_DB_ADMIN
    OPUS4_DEV_DB_USER

The access information for the database are stored in `database.ini`.

TODO How is `database.ini` created?