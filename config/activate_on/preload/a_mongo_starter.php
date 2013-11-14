<?php

// NOTE: this has to load before the session manager, or bad things will happen if you're calling mongo from the login activators

// THINK: is there a way to do this faster on failure? asynch loading, then queue Mongo requests until completion or failure?

// OPT: this adds about 3ms per request locally (on 7ms for a base request)

try {
  MongoLib::initialize();
}
catch(Exception $e) {
  return ErrorLib::set_error("Error initializing Mongo database: " . var_export($e, true), 'mongo_starter');
}


// //EOT