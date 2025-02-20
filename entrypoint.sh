#!/bin/bash

for i in $(seq -f "%05g" 1 450); do
    if [ -f "/app/${i}/PosService" ]; then
        /app/${i}/PosService &
    fi
done

wait