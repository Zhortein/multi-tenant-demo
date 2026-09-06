#!/bin/sh
set -eu
# This script runs only against the disposable/local Compose server.
# Suppress vendor diagnostics: they may contain addressing and credentials.
provision() {
    mc alias set demo "$OBJECT_ENDPOINT" "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" || return 1
    for bucket in "$OBJECT_BUCKET_SHARED_V1" "$OBJECT_BUCKET_SHARED_V2" "$OBJECT_BUCKET_DEDICATED"; do
        mc mb --ignore-existing "demo/$bucket" || return 1
        mc anonymous set none "demo/$bucket" || return 1
    done
    mc admin user add demo "$OBJECT_ACCESS_KEY" "$OBJECT_SECRET_KEY" || return 1
    mc admin policy attach demo readwrite --user "$OBJECT_ACCESS_KEY" || return 1
    mc admin user add demo "$OBJECT_ROTATED_ACCESS_KEY" "$OBJECT_ROTATED_SECRET_KEY" || return 1
    mc admin policy attach demo readwrite --user "$OBJECT_ROTATED_ACCESS_KEY" || return 1
}
if provision >/dev/null 2>&1; then
    echo 'Local private object storage is provisioned.'
else
    echo 'Local object storage provisioning failed.' >&2
    exit 1
fi
