{...}: {
  check-unit = name: ''systemctl --user is-active ${name} &>/dev/null'';
}
