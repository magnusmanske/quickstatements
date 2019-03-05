#!/bin/bash
qdel bot
sleep 10
jsub -mem 4g -N bot -once -continuous /data/project/quickstatements/bot.php
