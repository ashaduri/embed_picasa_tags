
# Convert all png files to jpg retaining all tags.

for i in *.png; do convert -quality 90 $i jpgs/${i%%.*}.jpg; \
exiv2 -e a $i; mv ${i%%.*}.exv jpgs/; pushd jpgs; \
exiv2 -i a ${i%%.*}.jpg; rm -f ${i%%.*}.exv; popd; done

