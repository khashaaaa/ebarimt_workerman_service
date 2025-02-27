# import os
# import shutil
#
# START_FOLDER = 402
# END_FOLDER = 405
# BASE_PORT = 9000
# SOURCE_FOLDER = '00001'
# INI_FILENAME = 'posapi.ini'
#
#
# def update_posapi_ini(folder_path, folder_name, port_number):
#     ini_path = os.path.join(folder_path, INI_FILENAME)
#     with open(ini_path, 'r') as file:
#         content = file.read()
#     content = content.replace('name=vatps_00001.db', f'name=vatps_{folder_name}.db')
#     content = content.replace('port=9001', f'port={port_number}')
#     with open(ini_path, 'w') as file:
#         file.write(content)
#
#
# for i in range(START_FOLDER, END_FOLDER + 1):
#     folder_name = f'{i:05}'
#     dest_folder = folder_name
#     port_number = BASE_PORT + i
#
#     shutil.copytree(SOURCE_FOLDER, dest_folder)
#     update_posapi_ini(dest_folder, folder_name, port_number)
#
#     print(f'{folder_name}: completed')
#
# print("All folders have been processed.")

import os
import shutil

START_FOLDER = 2
END_FOLDER = 450
BASE_PORT = 9000
SOURCE_FOLDER = '00001'
INI_FILENAME = 'posapi.ini'

def update_posapi_ini(folder_path, folder_name, port_number):
    ini_path = os.path.join(folder_path, INI_FILENAME)
    with open(ini_path, 'r') as file:
        content = file.read()
    content = content.replace('name=vatps_00001.db', f'name=vatps_{folder_name}.db')
    content = content.replace('port=9001', f'port={port_number}')
    with open(ini_path, 'w') as file:
        file.write(content)


for i in range(START_FOLDER, END_FOLDER + 1):
    folder_name = f'{i:05}'
    dest_folder = folder_name
    port_number = BASE_PORT + i

    shutil.copytree(SOURCE_FOLDER, dest_folder)
    update_posapi_ini(dest_folder, folder_name, port_number)

    print(f'{folder_name}: completed')

print("All folders have been processed.")
