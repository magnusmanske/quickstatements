#!/bin/bash
qdel bot
sleep 10
jsub -l release=trusty -mem 4g -N bot -once -continuous /data/project/quickstatements/bot.php
