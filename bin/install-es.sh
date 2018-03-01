#!/usr/bin/env bash

if [ $# -lt 1 ]; then
  echo "usage: $0 <es-version>"
  exit 1
fi

ES_VERSION=$1
echo $JAVA_HOME

setup_es() {
  download_url=$1
  mkdir /tmp/elasticsearch
  wget -O - $download_url | tar xz --directory=/tmp/elasticsearch --strip-components=1
}

start_es() {
  echo "Starting Elasticsearch $ES_VERSION..."
  echo "/tmp/elasticsearch/bin/elasticsearch $1 > /tmp/elasticsearch.log &"
  /tmp/elasticsearch/bin/elasticsearch $1 > /tmp/elasticsearch.log &
}

if [[ "$ES_VERSION" == 2.* ]]; then
  setup_es https://download.elastic.co/elasticsearch/release/org/elasticsearch/distribution/tar/elasticsearch/${ES_VERSION}/elasticsearch-${ES_VERSION}.tar.gz
else
  setup_es https://artifacts.elastic.co/downloads/elasticsearch/elasticsearch-${ES_VERSION}.tar.gz
fi

# java_home='/usr/lib/jvm/java-8-oracle'
if [[ "$ES_VERSION" == 2.* ]]; then
  start_es '-Des.path.repo=/tmp'
else
  start_es '-Epath.repo=/tmp -Enetwork.host=_local_'
fi
