couch-to-postgres-php-dump
==========================

Couchdb to PostgreSQL php dump script.

Syncs couchdb docs to postgres table, can be stopped and will continue from where it left off.

It will also update the postgres table when the couchdb is changed so can be put on a cron job to keep the postgres table in sync with the couchdb - needs jsonb field type in postgres.

Will create table in postgres if it does not exist with the following structure:

    CREATE TABLE mytable
    (
      id text NOT NULL,
      doc jsonb,
      CONSTRAINT mytable_pkey PRIMARY KEY (id)
    )

This then allows sql queries to your couchdbs for example:

    SELECT id, doc->>'name' as name, doc->>'age' as age
    FROM  mytable
    WHERE doc->>'type'='Person'
    ORDER BY name


Get meta data information from the docs:

    SELECT DISTINCT doc->>'type' as doctype, jsonb_object_keys(doc) AS myfields
    FROM mytable
    ORDER BY doctype , myfields


Or something more complex:

Couchdb Settings doc:

    {
       "_id": "123456300ab77ddbbf8d00f01a7177c4",
       "name": "Someones Settings",
       "categories": [
           {
               "uuid": "123456300ab77ddbbf8d00f01a51ddea",
               "name": "Phone",
           },
           {
               "uuid": "123456300ab77ddbbf8d00f01a51c76f",
               "name": "Email",
           },
           {
               "uuid": "123456300ab77ddbbf8d00f01a51c40e",
               "name": "Mobile",
           },
           {
               "uuid": "1234560ecdf1977e4a85349b297846d1",
               "name": "Fax",
           }
       ],
       "type": "Settings"
    }

Couchdb Thing doc:

    {
       "_id": "0322ab45e3635521ae916b2a78ac40e5",
       "name": "Record1",
      "categories": [
           {
               "category_id": "123456300ab77ddbbf8d00f01a51c76f",
               "description": "Some text",               
               "state": "archived",
               "added_date": "2014-06-02"
           }, 
           {
               "category_id": "1234560ecdf1977e4a85349b297846d1",
               "description": "Some text",
               "state": "current",
               "added_date": "2014-04-13"
           }
       ],
       "type": "Thing"
    }   


Join the tables:


    WITH categories AS (
     SELECT 
	    jsonb_array_elements(doc->'categories')->>'name' AS name,
	    jsonb_array_elements(doc->'categories')->>'uuid' AS uuid
   	 FROM mytable
     WHERE doc @> '{"type": "Settings"}'
    ),

    mythings AS (

     SELECT id AS thing_id,
      doc->>'name' AS thing_name,
      jsonb_array_elements(doc->'categories')->>'uuid' AS category_uuid
     FROM mytable
     WHERE doc @> '{"type": "Thing"}'
    )

    SELECT mythings.thing_id, mythings.thing_name, mythings.category_uuid, 
           mysettings.name AS category_name 
    FROM mythings
    JOIN mysettings ON (category_uuid=uuid)
 

