{...}: {
  cat = file: ''    
    $(
      if [ -f ${file} ]; then 
        cat ${file}
      else 
        echo "can't read '${file}'!"
      fi
    )'';
}
