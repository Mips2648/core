if (file_exists('/media/boot/multiboot/meson64_odroidc2.dtb.linux')) {
echo 'Update Jeedom repository';
shell_exec('sudo cp /etc/ca-certificates.conf /etc/ca-certificates.conf.orig');
shell_exec('sudo sed -i s%mozilla/DST_Root_CA_X3.crt%!mozilla/DST_Root_CA_X3.crt%g /etc/ca-certificates.conf');
shell_exec('sudo update-ca-certificates');
}